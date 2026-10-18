using System.ComponentModel;
using System.Diagnostics;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Starts a Windows process SUSPENDED, assigns its process handle to a
/// KILL_ON_JOB_CLOSE Job Object, and ONLY THEN resumes the primary thread.
/// Closing the sole job handle (including parent process crash) kills child
/// and descendants. A failed Win32 step terminates the suspended child.
/// No app/DB activation logic is present in this process-lifetime primitive.
/// </summary>
public sealed class WindowsJobChildProcess : IDisposable
{
    public const string JobAssignmentFailed = "ENGINE_JOB_ASSIGNMENT_FAILED";
    private const uint CreateSuspended = 0x00000004;
    private const uint CreateNoWindow = 0x08000000;
    private const uint StartfUseStdHandles = 0x00000100;
    private const uint ExtendedLimitInformationClass = 9;
    private const uint JobObjectLimitKillOnJobClose = 0x00002000;
    private const uint Infinite = 0xffffffff;
    private const uint HandleFlagInherit = 0x00000001;
    private readonly Process _process;
    private readonly IntPtr _job;
    private readonly AnonymousPipeServerStream _stdout;
    private readonly AnonymousPipeServerStream _stderr;
    private readonly AnonymousPipeServerStream _stdin;
    private bool _disposed;

    public int ProcessId => _process.Id;

    private WindowsJobChildProcess(Process process, IntPtr job,
        AnonymousPipeServerStream stdout, AnonymousPipeServerStream stderr,
        AnonymousPipeServerStream stdin)
    {
        _process = process;
        _job = job;
        _stdout = stdout;
        _stderr = stderr;
        _stdin = stdin;
    }

    public static WindowsJobChildProcess Start(string exe, IReadOnlyList<string> arguments, string workingDirectory)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Windows Job Objects require Windows.");
        if (!Path.IsPathFullyQualified(exe) || !File.Exists(exe))
            throw new FileNotFoundException("Qualified child executable is missing.", exe);
        if (!Path.IsPathFullyQualified(workingDirectory) || !Directory.Exists(workingDirectory))
            throw new DirectoryNotFoundException("Child working directory is invalid.");
        if (arguments.Count > 64 || arguments.Any(static x => x is null || x.Contains('\0')))
            throw new InvalidDataException("Unsafe child argument list.");

        // AnonymousPipeServerStream's client-side handles are inheritable.
        // Server-side handles are explicitly non-inheritable to avoid keeping
        // a read endpoint alive inside the child on shutdown.
        var stdout = new AnonymousPipeServerStream(PipeDirection.In, HandleInheritability.Inheritable);
        var stderr = new AnonymousPipeServerStream(PipeDirection.In, HandleInheritability.Inheritable);
        var stdin = new AnonymousPipeServerStream(PipeDirection.Out, HandleInheritability.Inheritable);
        IntPtr job = IntPtr.Zero;
        ProcessInformation info = default;
        Process? process = null;
        try
        {
            if (!SetHandleInformation(stdout.SafePipeHandle.DangerousGetHandle(), HandleFlagInherit, 0) ||
                !SetHandleInformation(stderr.SafePipeHandle.DangerousGetHandle(), HandleFlagInherit, 0) ||
                !SetHandleInformation(stdin.SafePipeHandle.DangerousGetHandle(), HandleFlagInherit, 0))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to protect parent pipe handles.");

            job = CreateJobObject(IntPtr.Zero, null);
            if (job == IntPtr.Zero)
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Cannot create kill-on-close job.");

            var limits = new JobObjectExtendedLimitInformation
            {
                BasicLimitInformation = new JobObjectBasicLimitInformation
                {
                    LimitFlags = JobObjectLimitKillOnJobClose
                }
            };
            var length = Marshal.SizeOf<JobObjectExtendedLimitInformation>();
            var memory = Marshal.AllocHGlobal(length);
            try
            {
                Marshal.StructureToPtr(limits, memory, false);
                if (!SetInformationJobObject(job, ExtendedLimitInformationClass, memory, (uint)length))
                    throw new Win32Exception(Marshal.GetLastWin32Error(), "Cannot configure kill-on-close job.");
            }
            finally { Marshal.FreeHGlobal(memory); }

            var startup = new StartupInfo
            {
                Cb = (uint)Marshal.SizeOf<StartupInfo>(),
                DwFlags = StartfUseStdHandles,
                StdInput = stdin.ClientSafePipeHandle.DangerousGetHandle(),
                StdOutput = stdout.ClientSafePipeHandle.DangerousGetHandle(),
                StdError = stderr.ClientSafePipeHandle.DangerousGetHandle()
            };
            var cmd = new StringBuilder(Quote(exe));
            foreach (var arg in arguments)
                cmd.Append(' ').Append(Quote(arg));
            if (!CreateProcess(exe, cmd, IntPtr.Zero, IntPtr.Zero, true,
                    CreateSuspended | CreateNoWindow, IntPtr.Zero,
                    workingDirectory, ref startup, out info))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Cannot create suspended child.");

            // Critical pre-execution boundary. On failure child has not run.
            if (!AssignProcessToJobObject(job, info.Process))
                throw new InvalidOperationException(JobAssignmentFailed,
                    new Win32Exception(Marshal.GetLastWin32Error()));

            process = Process.GetProcessById((int)info.ProcessId);
            if (ResumeThread(info.Thread) == Infinite)
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to resume job-bound child.");

            stdout.DisposeLocalCopyOfClientHandle();
            stderr.DisposeLocalCopyOfClientHandle();
            stdin.DisposeLocalCopyOfClientHandle();
            // Child stdin must see EOF if it tries to read.
            stdin.Dispose();

            return new WindowsJobChildProcess(process, job, stdout, stderr, stdin);
        }
        catch
        {
            if (info.Process != IntPtr.Zero)
                _ = TerminateProcess(info.Process, 123);
            process?.Dispose();
            if (job != IntPtr.Zero) CloseHandle(job);
            stdout.Dispose();
            stderr.Dispose();
            stdin.Dispose();
            throw;
        }
        finally
        {
            if (info.Thread != IntPtr.Zero) CloseHandle(info.Thread);
            if (info.Process != IntPtr.Zero) CloseHandle(info.Process);
        }
    }

    public WindowsJobChildOutcome WaitAndCapture(int timeoutMilliseconds)
    {
        if (_disposed) throw new ObjectDisposedException(nameof(WindowsJobChildProcess));
        if (timeoutMilliseconds is < 1 or > 600000) throw new ArgumentOutOfRangeException(nameof(timeoutMilliseconds));
        using var standardOutput = new StreamReader(_stdout, Encoding.UTF8, detectEncodingFromByteOrderMarks: true, leaveOpen: true);
        using var standardError = new StreamReader(_stderr, Encoding.UTF8, detectEncodingFromByteOrderMarks: true, leaveOpen: true);
        var stdoutTask = standardOutput.ReadToEndAsync();
        var stderrTask = standardError.ReadToEndAsync();
        var finished = _process.WaitForExit(timeoutMilliseconds);
        if (!finished)
        {
            // Closing the Job Object kills every child/descendant in this job.
            CloseHandle(_job);
            _disposed = true;
            try { _process.WaitForExit(5000); } catch { }
        }
        // CreateProcessW launched the process, so this managed Process was
        // attached via GetProcessById, not Process.Start. Process.ExitCode
        // throws InvalidOperationException for attached processes on .NET.
        // Read the OS exit status directly from the existing process handle.
        int exitCode = -1;
        if (finished)
        {
            if (!GetExitCodeProcess(_process.Handle, out var nativeExitCode))
                throw new Win32Exception(Marshal.GetLastWin32Error(),
                    "Unable to read a Job-bound child exit status.");
            exitCode = unchecked((int)nativeExitCode);
            if (!Task.WhenAll(stdoutTask, stderrTask).Wait(TimeSpan.FromSeconds(5)))
                throw new TimeoutException("Job-bound child output pipes did not close after process exit.");
        }
        var stdout = stdoutTask.IsCompleted ? stdoutTask.GetAwaiter().GetResult() : string.Empty;
        var stderr = stderrTask.IsCompleted ? stderrTask.GetAwaiter().GetResult() : string.Empty;
        return new WindowsJobChildOutcome(finished, exitCode, stdout, stderr, ProcessId);
    }

    private static string Quote(string argument)
    {
        if (argument.Length == 0) return "\"\"";
        if (!argument.Any(static c => char.IsWhiteSpace(c) || c == '"')) return argument;
        var result = new StringBuilder("\"");
        var slashes = 0;
        foreach (var ch in argument)
        {
            if (ch == '\\') { slashes++; continue; }
            if (ch == '"')
            {
                result.Append('\\', slashes * 2 + 1).Append('"');
                slashes = 0;
                continue;
            }
            result.Append('\\', slashes).Append(ch);
            slashes = 0;
        }
        result.Append('\\', slashes * 2).Append('"');
        return result.ToString();
    }

    public void Dispose()
    {
        if (_disposed)
        {
            _process.Dispose();
            _stdout.Dispose();
            _stderr.Dispose();
            _stdin.Dispose();
            return;
        }
        _disposed = true;
        CloseHandle(_job);
        _process.Dispose();
        _stdout.Dispose();
        _stderr.Dispose();
        _stdin.Dispose();
    }

    public sealed record WindowsJobChildOutcome(bool Finished, int ExitCode,
        string Stdout, string Stderr, int ProcessId);

    [StructLayout(LayoutKind.Sequential)]
    private struct StartupInfo
    {
        public uint Cb;
        public string? Reserved;
        public string? Desktop;
        public string? Title;
        public uint X;
        public uint Y;
        public uint XSize;
        public uint YSize;
        public uint XCountChars;
        public uint YCountChars;
        public uint FillAttribute;
        public uint DwFlags;
        public ushort ShowWindow;
        public ushort Reserved2;
        public IntPtr Reserved2Ptr;
        public IntPtr StdInput;
        public IntPtr StdOutput;
        public IntPtr StdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ProcessInformation
    {
        public IntPtr Process;
        public IntPtr Thread;
        public uint ProcessId;
        public uint ThreadId;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct JobObjectBasicLimitInformation
    {
        public long PerProcessUserTimeLimit;
        public long PerJobUserTimeLimit;
        public uint LimitFlags;
        public UIntPtr MinimumWorkingSetSize;
        public UIntPtr MaximumWorkingSetSize;
        public uint ActiveProcessLimit;
        public UIntPtr Affinity;
        public uint PriorityClass;
        public uint SchedulingClass;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct IoCounters
    {
        public ulong ReadOperationCount;
        public ulong WriteOperationCount;
        public ulong OtherOperationCount;
        public ulong ReadTransferCount;
        public ulong WriteTransferCount;
        public ulong OtherTransferCount;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct JobObjectExtendedLimitInformation
    {
        public JobObjectBasicLimitInformation BasicLimitInformation;
        public IoCounters IoInfo;
        public UIntPtr ProcessMemoryLimit;
        public UIntPtr JobMemoryLimit;
        public UIntPtr PeakProcessMemoryUsed;
        public UIntPtr PeakJobMemoryUsed;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, EntryPoint = "CreateProcessW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateProcess(string applicationName, StringBuilder commandLine,
        IntPtr processAttributes, IntPtr threadAttributes, [MarshalAs(UnmanagedType.Bool)] bool inheritHandles,
        uint creationFlags, IntPtr environment, string currentDirectory, ref StartupInfo startup,
        out ProcessInformation processInformation);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr CreateJobObject(IntPtr securityAttributes, string? name);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetInformationJobObject(IntPtr job, uint informationClass,
        IntPtr information, uint informationLength);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool TerminateProcess(IntPtr process, uint exitCode);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetExitCodeProcess(IntPtr process, out uint exitCode);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
}
