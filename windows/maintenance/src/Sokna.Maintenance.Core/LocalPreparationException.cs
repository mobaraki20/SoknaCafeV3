namespace Sokna.Maintenance.Core;

// Fixed stage labels and machine codes only; never expose PHP stderr, paths,
// database credentials or arbitrary exception text through the Web adapter.
public sealed class LocalPreparationException : InvalidOperationException
{
    public string Step { get; }
    public string ReasonCode { get; }
    public LocalPreparationException(string step, Exception cause)
        : base("LOCAL_PREPARE_" + step + "_FAILED", cause)
    {
        Step = step;
        var code = cause.Message;
        ReasonCode = code.Length is > 0 and <= 120 && code.All(static c =>
            char.IsAsciiLetterUpper(c) || char.IsAsciiDigit(c) || c == '_')
            ? code : cause.GetType().Name;
    }
}
