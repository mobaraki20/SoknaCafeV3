using Microsoft.Data.Sqlite;

if(args.Length!=2||args[0] is not ("seed" or "verify"))
    throw new ArgumentException("expected seed|verify and queue.db path");
var path=Path.GetFullPath(args[1]);
if(!File.Exists(path)) throw new InvalidOperationException("PRINT_DURABLE_QA_DATABASE_NOT_INITIALIZED");
using var db=new SqliteConnection(new SqliteConnectionStringBuilder{DataSource=path,Mode=SqliteOpenMode.ReadWrite,Pooling=false}.ToString());
db.Open();
using(var fk=db.CreateCommand()) {fk.CommandText="PRAGMA foreign_keys=ON;"; fk.ExecuteNonQuery();}
const long attempt=987000321;
const string req="r238-durable-receipt-987000321";
if(args[0]=="seed")
{
    using var tx=db.BeginTransaction();
    using var cmd=db.CreateCommand();cmd.Transaction=tx;
    cmd.CommandText="INSERT OR REPLACE INTO agent_meta(key,value) VALUES('r238_qa_marker','keep-across-independent-upgrades');";
    cmd.ExecuteNonQuery();
    cmd.CommandText="""
       INSERT INTO local_jobs(attempt_id,server_job_id,attempt_no,destination_key,queue_name,paper_width_mm,
          printable_width_mm,copies,layout_mode,payload_json,content_sha256,local_receipt_id,
          protected_lease_token,lease_expires_at,state,spooler_job_id,created_at,updated_at,last_error,server_scope)
       VALUES(987000321,987000320,1,'qa-printer-never-submit','QA-PRINT-NEVER-SUBMIT',80,
          72,1,'text','{}','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          'r238-qa-receipt-987000321','synthetic-lab-never-unprotect','2099-01-01T00:00:00Z',
          'unknown',NULL,'2026-11-15T00:00:00Z','2026-11-15T00:00:00Z','qa-no-real-printer','legacy-unbound');
       """;
    cmd.ExecuteNonQuery();
    cmd.CommandText="""
      INSERT INTO report_outbox(server_job_id,attempt_id,request_id,body_json,created_at,sent_at,
       permanent_error,delivery_state,agent_version,server_scope)
      VALUES(987000320,987000321,'r238-durable-receipt-987000321','{}','2026-11-15T00:00:00Z',NULL,
       0,'Pending','6.2.11','legacy-unbound');
      """;
    cmd.ExecuteNonQuery();
    tx.Commit();
    Console.WriteLine("PASS_R238_DURABLE_QUEUE_SEEDED_UNKNOWN_ATTEMPT_AND_PENDING_OUTBOX_NO_PRINTER");
}
else
{
    object Scalar(string q){using var c=db.CreateCommand();c.CommandText=q;return c.ExecuteScalar()??DBNull.Value;}
    var marker=Scalar("SELECT value FROM agent_meta WHERE key='r238_qa_marker'".ToString()) as string;
    var cnt=Convert.ToInt32(Scalar($"SELECT COUNT(*) FROM local_jobs WHERE attempt_id={attempt}"));
    var spool=Scalar($"SELECT spooler_job_id FROM local_jobs WHERE attempt_id={attempt}");
    var state=Scalar($"SELECT state FROM local_jobs WHERE attempt_id={attempt}") as string;
    var reportCnt=Convert.ToInt32(Scalar("SELECT COUNT(*) FROM report_outbox WHERE request_id='r238-durable-receipt-987000321' AND sent_at IS NULL"));
    if(marker!="keep-across-independent-upgrades"||cnt!=1||spool is not DBNull||
       state is null or "printed" or "success" or "completed"||reportCnt!=1)
       throw new InvalidOperationException($"DURABLE_QUEUE_CHANGED: marker={marker}, cnt={cnt}, spool={spool}, state={state}, report={reportCnt}");
    Console.WriteLine("PASS_R238_DURABLE_UNKNOWN_ATTEMPT_AND_PENDING_REPORT_PRESERVED_STATE="+state);
}