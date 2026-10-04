# Shared implementation contract

Runtime namespace `Wmos`; PSR-4 `src/`; main plugin `woocommerce-marketing-os.php`; textdomain `woocommerce-marketing-os`; REST `wmos/v1`; version initial `1.0.0-rc.1` until release gates pass. PHP8.3+. UTC timestamps `Y-m-d H:i:s`; UUID public identifiers, BIGINT internal IDs. Money integer minor units+currency+exponent. Dependencies explicit constructor injection.

Root owns lifecycle/bootstrap, Infrastructure/Database.php, Schema.php, Queue.php, Audit.php, Integration/WooCommerce.php, Tracking.php, health, test/release tooling. Domain agent owns Domain/Rules.php, Graph.php, Money.php; Application/Definitions.php, Segments.php, Automation.php, Measurement.php, Experiments.php. Channels agent owns Application/Contacts.php, Consent.php, Messaging.php, Programs.php, Promotions.php, Privacy.php; Infrastructure/Secrets.php, Providers/* and Contracts/* as needed. Admin agent owns Rest/*, assets/*, Platform/Admin.php. Do not edit another owner without coordination.

Database constructor accepts wpdb. Methods: table(name):string allowlist; db():wpdb; insert(table,array):array adds uuid/time/revision, returns complete row; get(table,uuid):?array; find(table,field,value):?array; list(table,filters=[],limit=25,afterId=0):array; update(table,uuid,array,expectedVersion=null):array with CAS; delete(table,uuid):void; transaction(callable):mixed using nested savepoints. JSON explicitly encode/decode with Json helper. `Database::now()` UTC string. `Database::uuid()` UUID. SQL prepared; optional sql needed through db/table with static field names. Rows retain numeric internal id only inside application. DatabaseException and ValidationException(RuntimeException) use safe messages.

All table columns common: id bigint unsigned PK,uuid char36 unique,created_at datetime,updated_at datetime,row_version bigint unsigned default1. Tables/extra columns:

- profiles: email_hash binary32 nullable unique,email_cipher longtext nullable,user_id bigint nullable unique,state varchar32 defaultactive,tags longtext JSON,attributes longtext JSON,erasure_epoch bigint default0,order_count bigint default0,revenue_minor bigint default0,currency char3,last_order_at datetime nullable.
- identities:profile_id bigint,kind varchar32,namespace varchar100,value_hash binary32,value_cipher longtext,verified_at datetime nullable; unique kind/namespace/hash.
- consents:profile_id bigint,purpose varchar64,channel varchar32,status varchar32,source varchar64,policy_version varchar64,evidence longtext,request_key char64 unique,effective_at datetime; immutable history; latest id under profile row lock; suppression prevents recontact.
- suppressions:profile_id bigint nullable,identity_hash binary32 nullable,channel varchar32,purpose varchar64,reason varchar64,scope_key char64 unique.
- definitions:kind varchar32,name varchar191,state varchar32,draft longtext JSON,published_version_id bigint nullable,cancel_epoch bigint default0; kinds campaign,automation,segment,promotion,program,experiment,asset,offline,event,influencer,content,partner.
- definition_versions:definition_id bigint,version bigint,body longtext JSON,digest char64; unique definition/version,immutable.
- memberships:definition_id bigint,generation bigint,profile_id bigint; unique definition/generation/profile.
- events:name varchar100,source varchar64,source_key char64,profile_id bigint nullable,object_type varchar32 nullable,object_id varchar100 nullable,properties longtext,context longtext,occurred_at datetime,processed_at datetime nullable;unique source/source_key.
- jobs:kind varchar64,operation_key char64 unique,payload longtext,state varchar32 defaultpending,attempts int default0,available_at datetime,lease_token char64 nullable,lease_until datetime nullable,last_error varchar191 nullable,expires_at datetime nullable; operations idempotent, manual ambiguous retries forbidden.
- runs:definition_id bigint,version_id bigint,profile_id bigint nullable,event_id bigint nullable,entry_key char64 unique,state varchar32 defaultpending,cancel_epoch bigint default0,context longtext.
- steps:run_id bigint,node_key varchar64,activation_key char64 unique,state varchar32,due_at datetime nullable,result longtext nullable; one durable node activation,split lineage included.
- messages:profile_id bigint nullable,channel varchar32,purpose varchar64,provider_uuid char36,state varchar32,logical_key char64 unique,content longtext encrypted,content_hash char64,definition_id bigint nullable,run_id bigint nullable,provider_ref varchar191 nullable,last_error varchar191 nullable,scheduled_at datetime,attempted_at datetime nullable,accepted_at datetime nullable.
- providers:type varchar64,name varchar191,state varchar32,configuration longtext safeJSON,secret longtext encrypted nullable.
- webhook_receipts:provider_id bigint,event_key char64,body_hash char64;unique provider/eventkey.
- links:slug varchar64 unique,destination varchar2048,definition_id bigint nullable,placement_uuid char36 nullable,dimensions longtext,state varchar32.
- touchpoints:profile_id bigint nullable,session_hash char64 nullable,link_id bigint nullable,definition_id bigint nullable,channel varchar32,occurred_at datetime,event_key char64 unique.
- conversions:order_id bigint unique,profile_id bigint nullable,state varchar32,net_minor bigint,currency char3,exponent int,paid_at datetime,source_digest char64.
- credits:conversion_id bigint,definition_id bigint nullable,model varchar32,weight bigint,amount_minor bigint,currency char3,source_digest char64;unique conversion/model/definition normalized via credit_key char64 unique.
- costs:definition_id bigint,currency char3,amount_minor bigint,source_key char64 unique,effective_at datetime.
- ledger:profile_id bigint,program_id bigint,kind varchar32,points bigint,amount_minor bigint nullable,currency char3 nullable,operation_key char64 unique,source_order_id bigint nullable,reverses_id bigint nullable,reason varchar191 nullable.
- program_accounts:profile_id bigint,program_id bigint,balance bigint default0,held bigint default0;unique profile/program.
- referrals:program_id bigint,referrer_id bigint,referee_id bigint nullable,code varchar64 unique,state varchar32,order_id bigint nullable.
- commissions:program_id bigint,affiliate_id bigint,order_id bigint,amount_minor bigint,currency char3,state varchar32,operation_key char64 unique,paid_at datetime nullable.
- assignments:definition_id bigint,version_id bigint,profile_id bigint nullable,unit_hash char64,variant varchar64,exposed_at datetime nullable,converted_at datetime nullable;unique version/unit.
- audit:actor_id bigint nullable,action varchar100,object_uuid char36 nullable,metadata longtext,correlation_id char36.
- aggregates:bucket_key char64 unique,metric varchar64,definition_id bigint nullable,currency char3,day date,value bigint,count bigint default0.
- carts:cart_key char64 unique,profile_id bigint nullable,fingerprint char64,state varchar32,last_activity datetime,generation bigint default1,order_id bigint nullable,contents longtext safeJSON.

Queue(Database,Audit): enqueue(kind,payload,operationKey,delaySeconds=0):array; register(kind,callable):void; tick():void; handler(jobRow,decodedPayload) must be idempotent. No remote I/O under transaction. Root schedules periodic AS wakeups; jobs authoritative. Exceptions RetryableException(reason,delay) reschedule; AmbiguousException halts; all other throws failed safely. No Task placeholders.

Audit(Database): record(action,objectUuid=null,metadata=[]):void with redact.

Public application service interface conventions: Definitions(Database,Audit,Queue) create(kind,name,body):array; save(uuid,body,expectedRevision):array; publish(uuid,expectedRevision):array; transition(uuid,state,expectedRevision):array; get(uuid):array; list(kind,limit=25,after=0):array. Rules::validate(ast); Rules::matches(ast,profile,facts=[]):?bool; Rules::compile(ast,alias='p'):{sql,args}. Graph::validate(body,actionRegistry=[]):void.

Contacts(Database,Secrets,Audit) create(email,userId=null,verified=false):array; get(uuid):array safe; raw(uuid):array internal; update(uuid,attributes,tags,revision):array; decryptEmail(row):string. Consent(Database,Audit) grant(profileUuid,purpose,channel,source,policy,evidence,operationKey):array; withdraw(profileUuid,purpose,channel,operationKey):array; allowed(profileUuid,purpose,channel):bool. Never infer grant from checkout/order.

Messaging(Database,Queue,Contacts,Consent,Secrets,Audit) plan(profileUuid,channel,providerUuid,content,logicalKey,purpose='marketing',definitionUuid=null,runUuid=null):array; dispatch(job,payload):void; providers APIs/save methods supplied agent; request(payload) schemas admin binds; Providers immutable accepted/ambiguous/rejected outcome.

Automation(Database,Definitions,Queue,Audit): setActions(array type=>callable):void at composition root; ingest(eventRow):void; process(job,payload):void; enter(definitionUuid,profileUuid,eventUuid,operationKey):array; cancel(runUuid):array. Action callable(config,run,step) returns safe result; root registers message/tag/coupon/points/webhook/review actions. Definitions publishes graph validates built-in actions including message,tag,coupon,points,webhook,review. Segments(Database,Queue,Audit) rebuild(definitionUuid):array; process(job,payload); preview(ast,limit=25):array.

Measurement(Database,Audit) reconcile(order):void public WC_Order input, snapshot via public getters; report(filters):array; track(row):void; refunds cumulative. Programs(Database,Queue,Audit) operations through create policies as definitions, earn/redeem/adjust/referral/commission APIs supplied by owner. Experiments(Database,Definitions,Audit) assign(defUuid,unit,profileUuid=null):array; expose(assignmentUuid):array; results(defUuid):array.

Application composition root supplies API an explicit services map inside Rest registration only (controller factory, not domain service locator). Resource controllers operate listed services, schemas permissions; no arbitrary database rows to browser. Admin boot onlyrestURL+nonce+capabilityflags, no credentials/PII. Use supported @wordpress element/components/apiFetch handles via platform dependencies, nobundled React. UI must have actual builders/screens not blankplaceholdertabs.

Primary retention raw90d/log30d/messagesbody90d/history365d; configurable explicit financialpolicy. Storefront only bounded dirty/eventcapture. All external addresses allowlisted fixed provider hosts; remotewebhooks fixedapproved publicHTTPS domains. No internalWoo namespace. Every paidfactref/cancel/refundsemantics deterministic validated under repeatedhooks.
