using Microsoft.Data.Sqlite;
using System.Text.Json;

internal static class Program
{
    private static readonly HashSet<string> Unsafe = new(StringComparer.Ordinal)
    {
        "Reserved","Claimed","WorkerLaunching","Submitted"
    };
    public static int Main(string[] args)
    {
        var result = new SortedDictionary<string,object?>(StringComparer.Ordinal)
        {
            ["format"]="sokna-printagent-upgrade-queue-guard-v1",
            ["allow_mutation"]=false,
            ["database_state"]="unchecked",
            ["active_jobs"]=0,
            ["reason"]="UNKNOWN"
        };
        try
        {
            if(!OperatingSystem.IsWindows())throw new InvalidOperationException("WINDOWS_ONLY");
            if(args.Length!=2 || args[0]!="--data-root")throw new ArgumentException("DATA_ROOT_ARGUMENT_REQUIRED");
            var dir=Path.GetFullPath(args[1]);
            if(!Path.IsPathFullyQualified(dir))throw new InvalidOperationException("DATA_ROOT_NOT_ABSOLUTE");
            var file=Path.Combine(dir,"queue.db");
            if(!File.Exists(file))
            {
                result["database_state"]="absent";
                result["reason"]="NO_EXISTING_QUEUE";
                result["allow_mutation"]=true;
                Console.WriteLine(JsonSerializer.Serialize(result));
                return 0;
            }
            var opts=new SqliteConnectionStringBuilder
            {
                DataSource=file,
                Mode=SqliteOpenMode.ReadOnly,
                Cache=SqliteCacheMode.Private,
                Pooling=false,
                DefaultTimeout=3
            };
            using var connection=new SqliteConnection(opts.ToString());
            connection.Open();
            using var validate=connection.CreateCommand();
            validate.CommandText="PRAGMA quick_check(1);";
            var integrity=(string?)validate.ExecuteScalar();
            if(integrity!="ok")throw new InvalidDataException("QUEUE_INTEGRITY_FAILED");
            using var tables=connection.CreateCommand();
            tables.CommandText="SELECT count(*) FROM sqlite_master WHERE type='table' AND name='local_jobs'";
            if(Convert.ToInt32(tables.ExecuteScalar())!=1)throw new InvalidDataException("LOCAL_JOBS_SCHEMA_UNRECOGNIZED");
            using var cmd=connection.CreateCommand();
            cmd.CommandText="SELECT state,COUNT(*) FROM local_jobs GROUP BY state";
            int active=0,total=0;
            using var reader=cmd.ExecuteReader();
            while(reader.Read())
            {
                var state=reader.GetString(0);
                var count=reader.GetInt32(1);
                total+=count;
                if(Unsafe.Contains(state))active+=count;
                else if(state is not ("ReportPending" or "SafeFailed" or "Unknown" or "RecoveryHold" or "Resolved"))
                    throw new InvalidDataException("UNRECOGNIZED_QUEUE_STATE");
            }
            result["active_jobs"]=active;
            result["total_jobs"]=total;
            result["database_state"]="read_only_verified";
            result["allow_mutation"]=active==0;
            result["reason"]=active==0?"QUIESCENT":"IN_FLIGHT_JOBS_BLOCKED";
            Console.WriteLine(JsonSerializer.Serialize(result));
            return active==0?0:23;
        }
        catch(Exception ex)
        {
            result["reason"]=ex switch
            {
                SqliteException se => "QUEUE_SQLITE_ERROR_"+se.SqliteErrorCode,
                InvalidDataException or ArgumentException or InvalidOperationException => ex.Message,
                _ => "QUEUE_READ_ONLY_INSPECTION_FAILED"
            };
            Console.WriteLine(JsonSerializer.Serialize(result));
            return 24;
        }
    }
}