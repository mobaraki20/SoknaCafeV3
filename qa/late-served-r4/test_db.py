#!/usr/bin/env python3
"""Tests real MariaDB DDL and SQL transaction primitives, NOT full Sokna PHP service."""
import os, sys, time, pathlib, uuid, threading, concurrent.futures
import pymysql
from pymysql.err import IntegrityError, OperationalError
ROOT=pathlib.Path(__file__).resolve().parent
HOST=os.getenv('MARIADB_HOST','127.0.0.1')
PASS=os.getenv('MARIADB_PASSWORD','qa_root_local_only')
DB='sokna_late_served_r4_qa'
def con(db=None):
    return pymysql.connect(host=HOST,user='root',password=PASS,db=db,charset='utf8mb4',autocommit=True,connect_timeout=10)
def sqlexec(conn,sql,args=None):
    with conn.cursor() as cur:
        cur.execute(sql,args)
        return cur.fetchall()
def validate(label,cond):
    if not cond: raise AssertionError(label)
    print('PASS',label,flush=True)
def split_sql(source):
    lines=[line for line in source.splitlines() if not line.lstrip().startswith('--')]
    return [s.strip() for s in '\n'.join(lines).split(';') if s.strip()]
def apply_file(c,name):
    for s in split_sql((ROOT/name).read_text()): sqlexec(c,s)
def insert_order(c,token,session=1):
    with c.cursor() as cur:
        cur.execute('''INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,order_context,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
        VALUES(%s,%s,1,%s,'staff','accounted','table_service',100000,%s,'2026-10-25','QA','QA','04:00')''',(token,token,session,int(uuid.uuid4().int%2000000000)))
        return cur.lastrowid

base=con()
sqlexec(base,f'DROP DATABASE IF EXISTS `{DB}`')
sqlexec(base,f'CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
base.close()
c=con(DB)
try:
    apply_file(c,'baseline-reduced.sql')
    sqlexec(c,"INSERT INTO users(id,username,password_hash,display_name,role) VALUES(1,'qa_admin','none','QA','admin')")
    sqlexec(c,"INSERT INTO cafe_tables(id,name,table_number,code,access_token) VALUES(1,'QA Table',1,'qa-t1','qa-access')")
    sqlexec(c,"INSERT INTO table_sessions(id,public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(1,'qa-s1',1,'active',1,'2026-10-25','QA','QA','04:00')")
    insert_order(c,'base-'+uuid.uuid4().hex)
    apply_file(c,'0076_late_served_accounting.sql')
    validate('0076 DDL initial',sqlexec(c,"SHOW COLUMNS FROM orders LIKE 'accounting_origin'")[0][4]=='regular')
    apply_file(c,'0076_late_served_accounting.sql')
    validate('0076 repeatable migration',len(sqlexec(c,"SHOW TABLES LIKE 'late_served_accounting_receipts'"))==1)
    validate('existing ordinary orders retain regular default',sqlexec(c,"SELECT COUNT(*) FROM orders WHERE accounting_origin='regular'")[0][0]==1)
    order=insert_order(c,'fk-'+uuid.uuid4().hex)
    sqlexec(c,'UPDATE orders SET accounting_origin=%s WHERE id=%s',('late_served',order))
    sqlexec(c,"INSERT INTO late_served_accounting_receipts(request_id,request_fingerprint,order_id,session_id,actor_user_id) VALUES(%s,%s,%s,1,1)",('test-uniqueness-00000001','a'*64,order))
    def expect_fk_or_unique(label,sql,args):
        try: sqlexec(c,sql,args)
        except IntegrityError: print('PASS',label,flush=True)
        else: raise AssertionError(label)
    insert_sql='INSERT INTO late_served_accounting_receipts(request_id,request_fingerprint,order_id,session_id,actor_user_id) VALUES(%s,%s,%s,%s,%s)'
    expect_fk_or_unique('request_id unique',insert_sql,('test-uniqueness-00000001','a'*64,order,1,1))
    expect_fk_or_unique('order_id unique',insert_sql,('test-uniqueness-00000002','a'*64,order,1,1))
    order2=insert_order(c,'fk2-'+uuid.uuid4().hex)
    expect_fk_or_unique('actor FK',insert_sql,('test-uniqueness-00000003','a'*64,order2,1,99999))
    expect_fk_or_unique('session FK',insert_sql,('test-uniqueness-00000004','a'*64,order2,99999,1))
    expect_fk_or_unique('order FK',insert_sql,('test-uniqueness-00000005','a'*64,99999999,1,1))
    # Rollback after order was created but receipt insert failed
    sqlexec(c,'START TRANSACTION')
    start=sqlexec(c,'SELECT COUNT(*) FROM orders')[0][0]
    order3=insert_order(c,'rolled-'+uuid.uuid4().hex)
    try: sqlexec(c,insert_sql,('test-uniqueness-00000001','x'*64,order3,1,1))
    except IntegrityError: sqlexec(c,'ROLLBACK')
    else: raise AssertionError('expected duplicate during rollback')
    validate('atomic rollback order+receipt',sqlexec(c,'SELECT COUNT(*) FROM orders')[0][0]==start)
    # Transaction/lock simulation matches only documented table->session->receipt pattern.
    # It does NOT call LateServedAccountingService nor OrderCommitService.
    def attempt(req,actor=1,fail_after_insert=False):
        x=con(DB)
        try:
            sqlexec(x,'SET SESSION innodb_lock_wait_timeout=10')
            sqlexec(x,'START TRANSACTION')
            sqlexec(x,'SELECT id,active FROM cafe_tables WHERE id=1 FOR UPDATE')
            sess=sqlexec(x,'SELECT id,status FROM table_sessions WHERE id=1 FOR UPDATE')[0]
            already=sqlexec(x,'SELECT order_id,request_fingerprint FROM late_served_accounting_receipts WHERE request_id=%s FOR UPDATE',(req,))
            if already:
                sqlexec(x,'COMMIT')
                return 'duplicate',already[0][0]
            if sess[1]!='active':
                sqlexec(x,'ROLLBACK')
                return 'closed',None
            order_id=insert_order(x,'parallel-'+uuid.uuid4().hex)
            sqlexec(x,'UPDATE orders SET accounting_origin=%s WHERE id=%s',('late_served',order_id))
            sqlexec(x,insert_sql,(req,'a'*64,order_id,1,actor))
            if fail_after_insert:
                sqlexec(x,'ROLLBACK');return 'rolled_back',order_id
            time.sleep(.12)
            sqlexec(x,'COMMIT')
            return 'created',order_id
        except Exception:
            sqlexec(x,'ROLLBACK')
            raise
        finally:x.close()
    request='qa-same-request-000000000001'
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        fut=[pool.submit(attempt,request) for _ in range(2)]
        results=[f.result(timeout=25) for f in fut]
    validate('parallel same request exactly once',sorted(r[0] for r in results)==['created','duplicate'] and results[0][1]==results[1][1])
    validate('parallel receipt row only once',sqlexec(c,'SELECT COUNT(*) FROM late_served_accounting_receipts WHERE request_id=%s',(request,))[0][0]==1)
    before=sqlexec(c,'SELECT COUNT(*) FROM orders')[0][0]
    status,_=attempt('qa-failure-before-commit-000001',fail_after_insert=True)
    validate('injected failure rollback',status=='rolled_back' and sqlexec(c,'SELECT COUNT(*) FROM orders')[0][0]==before)
    sqlexec(c,"UPDATE table_sessions SET status='closed',live_table_guard=NULL WHERE id=1")
    sqlexec(c,'UPDATE cafe_tables SET active=0 WHERE id=1')
    validate('retry already-committed after close',attempt(request)[0]=='duplicate')
    validate('new request denied after close',attempt('qa-new-after-close-0000000001')[0]=='closed')
    print('PASS MariaDB reduced-schema migration and SQL transaction safety checks',flush=True)
finally:
    c.close()
    base=con()
    try:sqlexec(base,f'DROP DATABASE IF EXISTS `{DB}`')
    finally:base.close()