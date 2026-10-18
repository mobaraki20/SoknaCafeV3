using System.Text;

namespace Sokna.Maintenance.Core;

/// <summary>
/// An OS file-handle lease shared across different Windows processes and user sessions.
/// Keep the open handle for the ENTIRE mutation; never delete the lock file on exit,
/// since deleting/recreating can admit a second writer holding an old inode/handle.
/// This is a mutual-exclusion primitive, NOT proof of Supervisor authorization.
/// </summary>
public sealed class MachineMutationLease : IDisposable
{
    private readonly FileStream _stream;
    public string Owner { get; }
    public int ProcessId { get; }
    public bool IsDisposed { get; private set; }
    public const string ConflictCode = "MACHINE_MUTATION_LEASE_BUSY";
    public const string UnavailableCode = "MACHINE_MUTATION_LEASE_UNAVAILABLE";
    private MachineMutationLease(FileStream stream, string owner)
    {
        _stream = stream;
        Owner = owner;
        ProcessId = Environment.ProcessId;
    }

    public static MachineMutationLease Acquire(MaintenancePaths paths, string owner)
    {
        ArgumentNullException.ThrowIfNull(paths);
        if (string.IsNullOrWhiteSpace(owner) || owner.Length > 100 ||
            owner.Any(static c => !(char.IsAsciiLetterOrDigit(c) || c is '-' or '_' or '.')))
            throw new InvalidDataException("Machine mutation lease owner is invalid.");
        var directory = Path.GetFullPath(paths.UpdateRoot);
        var lockPath = Path.GetFullPath(paths.MachineMutationLockPath);
        if (!string.Equals(Path.GetDirectoryName(lockPath), directory, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Mutation lease path escaped the owned update directory.");
        try
        {
            Directory.CreateDirectory(directory);
            if ((File.GetAttributes(directory) & FileAttributes.ReparsePoint) != 0 ||
                (File.Exists(lockPath) && (File.GetAttributes(lockPath) & FileAttributes.ReparsePoint) != 0))
                throw new InvalidDataException("Reparse-point mutation lease path is forbidden.");
            // Readers can inspect the owner for diagnostics. No second writer can
            // gain a handle on this file until disposal or operating-system crash cleanup.
            var stream = new FileStream(lockPath, FileMode.OpenOrCreate,
                FileAccess.ReadWrite, FileShare.Read, 4096, FileOptions.WriteThrough);
            try
            {
                stream.SetLength(0);
                var evidence = $"format=sokna-machine-mutation-lease-v1;owner={owner};pid={Environment.ProcessId};started_utc={DateTimeOffset.UtcNow:O}\n";
                var bytes = Encoding.UTF8.GetBytes(evidence);
                stream.Write(bytes);
                stream.Flush(flushToDisk: true);
                return new MachineMutationLease(stream, owner);
            }
            catch
            {
                stream.Dispose();
                throw;
            }
        }
        catch (IOException ex)
        {
            throw new InvalidOperationException(ConflictCode, ex);
        }
        catch (UnauthorizedAccessException ex)
        {
            throw new InvalidOperationException(UnavailableCode, ex);
        }
    }

    public void Dispose()
    {
        IsDisposed = true;
        _stream.Dispose();
    }
}
