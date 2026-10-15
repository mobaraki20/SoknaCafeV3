using System.Text;
using System.Text.Json;

namespace Sokna.Maintenance.Core;

public static class ReleaseSetCanonicalizer
{
    public static byte[] CanonicalBytesWithBlankSignatureValue(ReadOnlySpan<byte> manifestUtf8)
    {
        using var document = JsonDocument.Parse(manifestUtf8);
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream, new JsonWriterOptions { Indented = false }))
        {
            WriteCanonical(writer, document.RootElement, isSignatureObject: false);
        }
        return stream.ToArray();
    }

    private static void WriteCanonical(Utf8JsonWriter writer, JsonElement element, bool isSignatureObject)
    {
        switch (element.ValueKind)
        {
            case JsonValueKind.Object:
                writer.WriteStartObject();
                foreach (var property in element.EnumerateObject().OrderBy(static p => p.Name, StringComparer.Ordinal))
                {
                    writer.WritePropertyName(property.Name);
                    if (isSignatureObject && property.NameEquals("value"))
                    {
                        writer.WriteStringValue(string.Empty);
                    }
                    else
                    {
                        WriteCanonical(writer, property.Value, property.NameEquals("signature"));
                    }
                }
                writer.WriteEndObject();
                break;
            case JsonValueKind.Array:
                writer.WriteStartArray();
                foreach (var item in element.EnumerateArray()) WriteCanonical(writer, item, false);
                writer.WriteEndArray();
                break;
            case JsonValueKind.String:
                writer.WriteStringValue(element.GetString());
                break;
            case JsonValueKind.Number:
                element.WriteTo(writer);
                break;
            case JsonValueKind.True:
                writer.WriteBooleanValue(true);
                break;
            case JsonValueKind.False:
                writer.WriteBooleanValue(false);
                break;
            case JsonValueKind.Null:
                writer.WriteNullValue();
                break;
            default:
                throw new InvalidDataException($"Unsupported JSON value kind in Release Set: {element.ValueKind}.");
        }
    }

    public static string Sha256Hex(ReadOnlySpan<byte> bytes)
        => Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(bytes)).ToLowerInvariant();
}
