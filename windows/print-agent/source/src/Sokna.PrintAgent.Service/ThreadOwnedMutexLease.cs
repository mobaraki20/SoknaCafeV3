namespace Sokna.PrintAgent.Service;

/// <summary>
/// Owns the legacy global mutex on a dedicated OS thread. A Windows Mutex is
/// thread-affine: releasing it from an async continuation can crash the service
/// during SCM Stop. This lease retains the existing global name (and therefore
/// compatibility with already installed Print Agent instances) while allowing
/// its caller to await and dispose from any managed thread.
/// </summary>
internal sealed class ThreadOwnedMutexLease : IDisposable
{
    private readonly ManualResetEventSlim _ready = new(false);
    private readonly ManualResetEventSlim _shutdown = new(false);
    private readonly Thread _ownerThread;
    private Exception? _startupError;
    private Exception? _releaseError;
    private bool _ownsMutex;
    private int _disposed;

    private ThreadOwnedMutexLease(string mutexName)
    {
        _ownerThread = new Thread(() => RunOwner(mutexName))
        {
            IsBackground = true,
            Name = "Sokna Print singleton mutex owner"
        };
        _ownerThread.Start();
        _ready.Wait();
    }

    public bool RecoveredAbandoned { get; private set; }

    public static ThreadOwnedMutexLease Acquire(string mutexName)
    {
        var lease = new ThreadOwnedMutexLease(mutexName);
        if (lease._startupError is not null)
        {
            var error = lease._startupError;
            lease.Dispose();
            throw new InvalidOperationException("Sokna Print singleton mutex could not be acquired.", error);
        }

        if (!lease._ownsMutex)
        {
            lease.Dispose();
            throw new InvalidOperationException("نمونه دیگری از Sokna Print Agent فعال است.");
        }

        return lease;
    }

    private void RunOwner(string name)
    {
        Mutex? mutex = null;
        try
        {
            mutex = new Mutex(false, name);
            try
            {
                _ownsMutex = mutex.WaitOne(TimeSpan.Zero);
            }
            catch (AbandonedMutexException)
            {
                // The abandoned mutex is acquired by this thread. Preserve the
                // existing recovery behavior while guaranteeing same-thread release.
                _ownsMutex = true;
                RecoveredAbandoned = true;
            }

            _ready.Set();
            if (_ownsMutex)
                _shutdown.Wait();
        }
        catch (Exception e)
        {
            _startupError = e;
            _ready.Set();
        }
        finally
        {
            if (_ownsMutex)
            {
                try { mutex!.ReleaseMutex(); }
                catch (Exception e) { _releaseError = e; }
            }

            mutex?.Dispose();
        }
    }

    public void Dispose()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0)
            return;

        _shutdown.Set();
        _ownerThread.Join();
        _ready.Dispose();
        _shutdown.Dispose();

        if (_releaseError is not null)
            throw new InvalidOperationException("Sokna Print singleton mutex release failed on its owning thread.", _releaseError);
    }
}
