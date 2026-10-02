namespace Sokna.PrintAgent.Core;

public static class AgentVersionCompatibility
{
    public static bool IsSupported(string currentVersion,string minimumVersion)
        =>TryParseCore(currentVersion,out var current)
          &&TryParseCore(minimumVersion,out var minimum)
          &&current.CompareTo(minimum)>=0;

    public static void EnsureSupported(string currentVersion,string minimumVersion)
    {
        if(!TryParseCore(currentVersion,out var current))
            throw new InvalidDataException($"Agent version نامعتبر است: {currentVersion}");
        if(!TryParseCore(minimumVersion,out var minimum))
            throw new InvalidDataException($"minimum_agent_version نامعتبر است: {minimumVersion}");
        if(current.CompareTo(minimum)<0)
            throw new InvalidOperationException($"Print Agent {currentVersion} قدیمی‌تر از حداقل نسخهٔ پشتیبانی‌شدهٔ Server ({minimumVersion}) است؛ staged rollback/update باید متوقف شود.");
    }

    private static bool TryParseCore(string value,out Version version)
    {
        version=new Version(0,0,0);
        value=(value??string.Empty).Trim();
        if(value.Length==0)return false;
        var core=value.Split(['-','+'],2,StringSplitOptions.RemoveEmptyEntries)[0];
        var parts=core.Split('.');
        if(parts.Length<2||parts.Length>4||parts.Any(p=>!int.TryParse(p,out var n)||n<0))return false;
        while(parts.Length<3)parts=[..parts,"0"];
        return Version.TryParse(string.Join('.',parts),out version!);
    }
}
