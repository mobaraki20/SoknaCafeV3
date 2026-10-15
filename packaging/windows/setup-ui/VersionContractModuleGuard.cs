using System.Runtime.CompilerServices;

namespace Sokna.SetupUi;

internal static class VersionContractModuleGuard
{
    [ModuleInitializer]
    internal static void Initialize()
    {
        ComponentUpdatePlanner.AssertVersionContract();
    }
}
