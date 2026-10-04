<?php
declare(strict_types=1);
namespace Wmos\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Wmos\Contracts\{Provider,ProviderOutcome};
use Wmos\Infrastructure\{Database,Secrets,AmbiguousException};
use Wmos\Platform\Plugin;

final class ChannelProgramsPrivacyTest extends TestCase
{
    private array $services;private Database $database;private array $ownedPrograms=[];private array $settingsBefore=[];
    protected function setUp():void
    {
        if(getenv('WMOS_INTEGRATION')!=='1'){self::markTestSkipped('Requires isolated WordPress/WooCommerce.');}
        $this->services=Plugin::instance()->services();$this->database=Plugin::instance()->database();$this->settingsBefore=get_option('wmos_settings',[]);
        update_option('wmos_active',true,false);update_option('wmos_settings',array_replace(get_option('wmos_settings',[]),['enabled_modules'=>['email','sms','telegram','push','whatsapp','ads','social','webhook','programs','promotions','automations'],'financial_retention_days'=>365]),false);
    }
    protected function tearDown():void
    {
        if(!isset($this->database)){return;}update_option('wmos_settings',$this->settingsBefore,false);foreach($this->ownedPrograms as $uuid){$row=$this->database->get('definitions',$uuid);if($row&&$row['state']==='active'){$this->database->update('definitions',$uuid,['state'=>'paused','cancel_epoch'=>(int)$row['cancel_epoch']+1]);}}
    }
    private function contact(bool $verified=true,?int $user=null):array{return $this->services['contacts']->create('channels-'.bin2hex(random_bytes(6)).'@example.test',$user,$verified);}
    private function program(array $extra=[]):array
    {
        $row=$this->services['definitions']->create('program','Program test',array_replace(['type'=>'loyalty','currency'=>'USD','exponent'=>2,'financial_retention_days'=>365,'points_per_major'=>1,'earn_expiry_days'=>365],$extra));$published=$this->services['definitions']->publish($row['uuid'],(int)$row['row_version']);$this->ownedPrograms[]=$published['uuid'];return $published;
    }
    private function provider(RecordingProvider $adapter):array
    {
        $type='fixture_'.bin2hex(random_bytes(5));$this->services['messaging']->registerProvider($type,$adapter);return $this->services['messaging']->saveProvider($type,'Local transport fixture',['enabled'=>true,'policy_acknowledged'=>true],'fixture-token');
    }
    private function grant(array $contact,string $channel='email',string $purpose='marketing'):void{$this->services['consent']->grant($contact['uuid'],$purpose,$channel,'integration','v1',['explicit'=>true],Database::uuid());}
    private function order(int $user=0,string $amount='100.00'):\WC_Order
    {
        $order=wc_create_order(['customer_id'=>$user]);$item=new \WC_Order_Item_Product();$item->set_name('Channel fixture merchandise');$item->set_quantity(1);$item->set_subtotal($amount);$item->set_total($amount);$order->add_item($item);$order->set_currency('USD');$order->calculate_totals();$order->payment_complete();$order->save();return $order;
    }
    private function reconcile(\WC_Order $order):void
    {
        $after=0;
        do{$this->services['programs']->reconcileOrder($order,$after);$page=$this->database->list('definitions',['kind'=>'program'],100,$after);if($page){$after=(int)$page[array_key_last($page)]['id'];}}while(count($page)===100);
    }
    public function testPublishedAutomationPlansOnePinnedMessageThenDispatchesOnce():void
    {
        $adapter=new RecordingProvider();$provider=$this->provider($adapter);$contact=$this->contact();$this->grant($contact);
        $body=['nodes'=>[['id'=>'start','type'=>'trigger'],['id'=>'mail','type'=>'action','config'=>['action'=>'message','provider_uuid'=>$provider['uuid'],'channel'=>'email','content'=>['subject'=>'Pinned subject','text'=>'Pinned body']]],['id'=>'end','type'=>'exit']],'edges'=>[['from'=>'start','to'=>'mail'],['from'=>'mail','to'=>'end']]];
        $definition=$this->services['definitions']->create('automation','Owned email execution fixture',$body);$published=$this->services['definitions']->publish($definition['uuid'],(int)$definition['row_version']);$run=$this->services['automation']->enter($definition['uuid'],$contact['uuid'],null,'event:'.$contact['uuid']);
        // Drive only this run's actual durable jobs with valid stored leases; unrelated fixture jobs stay untouched.
        for($i=0;$i<4;++$i){foreach($this->database->list('steps',['run_id'=>$run['id'],'state'=>'ready'],100) as $step){$job=$this->database->find('jobs','operation_key',hash('sha256','automation_step:step:'.$step['uuid'].':initial'));$job=$this->database->update('jobs',$job['uuid'],['state'=>'running','lease_token'=>bin2hex(random_bytes(32)),'lease_until'=>gmdate('Y-m-d H:i:s',time()+120)]);$this->services['automation']->process($job,['run_uuid'=>$run['uuid'],'step_uuid'=>$step['uuid']]);$this->database->update('jobs',$job['uuid'],['state'=>'completed','lease_token'=>null,'lease_until'=>null]);}}
        self::assertSame('completed',$this->database->get('runs',$run['uuid'])['state']);$messages=$this->database->list('messages',['run_id'=>$run['id']],100);self::assertCount(1,$messages);self::assertSame((int)$published['published_version_id'],(int)$this->database->get('runs',$run['uuid'])['version_id']);$this->services['messaging']->dispatch(['attempts'=>1],['message_uuid'=>$messages[0]['uuid']]);self::assertSame('Pinned subject',$adapter->content['subject']);self::assertSame(1,$adapter->calls);
        $this->services['automation']->enter($definition['uuid'],$contact['uuid'],null,'event:'.$contact['uuid']);self::assertCount(1,$this->database->list('messages',['run_id'=>$run['id']],100));
    }
    public function testEncryptedContactAndIdentityAreNotImplicitConsent():void
    {
        $contact=$this->contact();$raw=$this->database->get('profiles',$contact['uuid']);self::assertStringNotContainsString($contact['email'],$raw['email_cipher']);self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','email'));
        $this->grant($contact);self::assertTrue($this->services['consent']->allowed($contact['uuid'],'marketing','email'));self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','sms'));self::assertFalse($this->services['consent']->allowed($contact['uuid'],'profiling','email'));
    }
    public function testDoubleOptInUsesEncryptedQueueThenVerifiesOnlyAfterChallenge():void
    {
        $contact=$this->contact(false);$request=$this->services['consent']->requestDoubleOptIn($contact['uuid'],'marketing','email','policy-v1',['explicit'=>true],'same-checkout');$repeat=$this->services['consent']->requestDoubleOptIn($contact['uuid'],'marketing','email','policy-v1',['explicit'=>true],'same-checkout');self::assertSame($request,$repeat);self::assertArrayNotHasKey('token',$request);
        $key=hash('sha256','consent.confirmation:confirmation:'.$request['uuid']);$job=$this->database->find('jobs','operation_key',$key);$payload=json_decode($job['payload'],true,16,JSON_THROW_ON_ERROR);self::assertArrayNotHasKey('token',$payload);$challenge=json_decode((new Secrets())->decrypt($payload['cipher'],'confirmation:'.$request['uuid']),true,16,JSON_THROW_ON_ERROR);self::assertStringNotContainsString($challenge['token'],$job['payload']);
        self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','email'));$this->services['consent']->confirm($challenge['token']);self::assertTrue($this->services['consent']->allowed($contact['uuid'],'marketing','email'));self::assertSame($contact['email'],$this->services['contacts']->destination($this->database->get('profiles',$contact['uuid']),'email'));
        $this->services['consent']->withdraw($contact['uuid'],'marketing','email','withdraw-after-confirm:'.$contact['uuid']);$this->services['consent']->confirm($challenge['token']);self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','email'),'Consumed challenge cannot regrant after withdrawal.');
    }
    public function testRecipientWithdrawalFencesQueuedSubmission():void
    {
        $adapter=new RecordingProvider();$provider=$this->provider($adapter);$contact=$this->contact();$this->grant($contact);$message=$this->services['messaging']->plan($contact['uuid'],'email',$provider['uuid'],['subject'=>'Test','text'=>'Content'],'withdraw-test:'.$contact['uuid']);$this->services['consent']->withdraw($contact['uuid'],'marketing','email','withdraw:'.$contact['uuid']);$this->services['messaging']->dispatch(['attempts'=>1],['message_uuid'=>$message['uuid']]);self::assertSame(0,$adapter->calls);self::assertSame('blocked',$this->database->get('messages',$message['uuid'])['state']);
    }
    public function testSubmissionHasOneClickUnsubscribeAndIdempotentMessageIdentity():void
    {
        $adapter=new RecordingProvider();$provider=$this->provider($adapter);$contact=$this->contact();$this->grant($contact);$key='submit:'.$contact['uuid'];$content=['subject'=>'Welcome','text'=>'Body'];$message=$this->services['messaging']->plan($contact['uuid'],'email',$provider['uuid'],$content,$key);self::assertSame($message['uuid'],$this->services['messaging']->plan($contact['uuid'],'email',$provider['uuid'],$content,$key)['uuid']);$this->services['messaging']->dispatch(['attempts'=>1],['message_uuid'=>$message['uuid']]);self::assertSame(1,$adapter->calls);self::assertSame('List-Unsubscribe=One-Click',$adapter->content['headers']['List-Unsubscribe-Post']);self::assertSame('submitted',$this->database->get('messages',$message['uuid'])['state']);
        preg_match('/token=([^>]+)/',$adapter->content['headers']['List-Unsubscribe'],$match);$this->services['messaging']->unsubscribe(rawurldecode($match[1]));self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','email'));
    }
    public function testAmbiguousNonIdempotentSubmissionCannotBeRetried():void
    {
        $adapter=new RecordingProvider('ambiguous');$provider=$this->provider($adapter);$contact=$this->contact();$this->grant($contact);$message=$this->services['messaging']->plan($contact['uuid'],'email',$provider['uuid'],['subject'=>'Test','text'=>'Content'],'ambiguous:'.$contact['uuid']);
        for($i=0;$i<2;++$i){try{$this->services['messaging']->dispatch(['attempts'=>$i+1],['message_uuid'=>$message['uuid']]);self::fail('Unknown remote outcome was retried.');}catch(AmbiguousException){}}
        self::assertSame(1,$adapter->calls);self::assertSame('ambiguous',$this->database->get('messages',$message['uuid'])['state']);
    }
    public function testCallbacksAreVerifiedDurablyQueuedAndDuplicateSafe():void
    {
        $contact=$this->contact();$this->services['contacts']->addIdentity($contact['uuid'],'phone','+1555'.random_int(1000000,9999999),'store',true);$this->grant($contact,'sms');$sid='AC'.str_repeat('a',32);$url='https://store.example/wp-json/wmos/v1/providers/callback';$provider=$this->services['messaging']->saveProvider('twilio','Callback fixture',['enabled'=>true,'policy_acknowledged'=>true,'account_sid'=>$sid,'from'=>'+15555550123','callback_url'=>$url],'twilio-fixture');$message=$this->services['messaging']->plan($contact['uuid'],'sms',$provider['uuid'],['text'=>'Fixture'],'receipt:'.$contact['uuid']);$ref='SM'.bin2hex(random_bytes(16));$this->database->update('messages',$message['uuid'],['provider_ref'=>$ref,'state'=>'submitted']);$form=['AccountSid'=>$sid,'MessageSid'=>$ref,'MessageStatus'=>'delivered'];ksort($form);$signed=$url;foreach($form as $k=>$v){$signed.=$k.$v;}$headers=['X-Twilio-Signature'=>base64_encode(hash_hmac('sha1',$signed,'twilio-fixture',true))];$raw=http_build_query($form);$first=$this->services['messaging']->callback($provider['uuid'],$raw,$headers,$url);self::assertTrue($first['accepted']);self::assertSame('submitted',$this->database->get('messages',$message['uuid'])['state']);self::assertTrue($this->services['messaging']->callback($provider['uuid'],$raw,$headers,$url)['duplicate']);$receipt=$this->database->list('webhook_receipts',['provider_id'=>$this->database->get('providers',$provider['uuid'])['id']],1)[0];self::assertStringNotContainsString($ref,$receipt['payload']);$this->services['messaging']->processReceipt(['attempts'=>1],['receipt_uuid'=>$receipt['uuid']]);self::assertSame('delivered',$this->database->get('messages',$message['uuid'])['state']);
    }
    public function testLoyaltyHoldRedeemAndAdjustmentPreserveImmutableBalance():void
    {
        $contact=$this->contact();$program=$this->program();$points=$this->services['programs'];$points->earn($contact['uuid'],$program['uuid'],100,'earn:'.$contact['uuid']);$hold=$points->reserve($contact['uuid'],$program['uuid'],60,'reserve:'.$contact['uuid']);self::assertSame('40',$points->balance($contact['uuid'],$program['uuid'])['available']);$redeemed=$points->redeem($hold['uuid'],'redeem');self::assertSame($redeemed,$points->redeem($hold['uuid'],'repeat'));$points->adjust($contact['uuid'],$program['uuid'],-10,'Correction','adjust:'.$contact['uuid']);$points->adjust($contact['uuid'],$program['uuid'],-10,'Correction','adjust:'.$contact['uuid']);self::assertSame('30',$points->balance($contact['uuid'],$program['uuid'])['balance']);$raw=$this->database->get('profiles',$contact['uuid']);$lots=$this->database->list('loyalty_lots',['profile_id'=>$raw['id'],'program_id'=>$program['id']],100);self::assertSame(30,array_sum(array_column($lots,'remaining')));
    }
    public function testRefundReconcilesEarnOnceAndPreservesDebtAfterSpentPoints():void
    {
        $user=wp_create_user('points-'.bin2hex(random_bytes(5)),wp_generate_password(30),'points-'.bin2hex(random_bytes(5)).'@example.test');$contact=$this->contact(true,$user);$program=$this->program();$order=$this->order($user);$points=$this->services['programs'];$this->reconcile($order);$hold=$points->reserve($contact['uuid'],$program['uuid'],80,'spend:'.$contact['uuid']);$points->redeem($hold['uuid'],'spent');$item=current($order->get_items('line_item'));$refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'50.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'50.00','refund_tax'=>[]]],'refund_payment'=>false]);self::assertInstanceOf(\WC_Order_Refund::class,$refund);$order=wc_get_order($order->get_id());$this->reconcile($order);self::assertSame('-30',$points->balance($contact['uuid'],$program['uuid'])['balance']);$this->reconcile($order);self::assertSame('-30',$points->balance($contact['uuid'],$program['uuid'])['balance']);
    }
    public function testAffiliatePartialRefundPaysNetAndDuplicatePayoutIsStable():void
    {
        $affiliate=$this->contact();$program=$this->program(['type'=>'affiliate','rate_bps'=>2500,'hold_days'=>0]);$order=$this->order();$points=$this->services['programs'];$commission=$points->recordCommission($program['uuid'],$affiliate['uuid'],$order->get_id(),10000,'USD','commission:'.$order->get_id());$item=current($order->get_items('line_item'));wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'25.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'25.00','refund_tax'=>[]]],'refund_payment'=>false]);$points->approveCommission($commission['uuid']);$payout=$points->markPayout([$commission['uuid']],'bank-confirmed-fixture','payout:'.$commission['uuid']);self::assertSame('1875',$payout['amount_minor']);self::assertSame($payout,$points->markPayout([$commission['uuid']],'bank-confirmed-fixture','payout:'.$commission['uuid']));self::assertSame(36,strlen($payout['payout_reference']));
    }
    public function testOrderBoundRedemptionReturnsProportionalPointsToOriginalLots():void
    {
        $contact=$this->contact();$program=$this->program(['points_per_major'=>0]);$order=$this->order();$points=$this->services['programs'];$points->earn($contact['uuid'],$program['uuid'],100,'purchase-points:'.$contact['uuid']);$hold=$points->reserve($contact['uuid'],$program['uuid'],80,'order-reserve:'.$contact['uuid'],1800,(int)$order->get_id());$points->redeem($hold['uuid'],'checkout');self::assertSame('20',$points->balance($contact['uuid'],$program['uuid'])['balance']);
        $item=current($order->get_items('line_item'));wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'50.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'50.00','refund_tax'=>[]]],'refund_payment'=>false]);$order=wc_get_order($order->get_id());$this->reconcile($order);self::assertSame('60',$points->balance($contact['uuid'],$program['uuid'])['balance']);$this->reconcile($order);self::assertSame('60',$points->balance($contact['uuid'],$program['uuid'])['balance']);
        $lot=$this->database->list('loyalty_lots',['profile_id'=>$this->database->get('profiles',$contact['uuid'])['id'],'program_id'=>$program['id']],1)[0];self::assertSame(60,(int)$lot['remaining']);self::assertNotNull($lot['expires_at']);
    }
    public function testRefundReleasesReservationsWhoseEarnedLotsWereReversed():void
    {
        $user=wp_create_user('held-'.bin2hex(random_bytes(5)),wp_generate_password(30),'held-'.bin2hex(random_bytes(5)).'@example.test');$contact=$this->contact(true,$user);$program=$this->program();$order=$this->order($user);$points=$this->services['programs'];$this->reconcile($order);$hold=$points->reserve($contact['uuid'],$program['uuid'],90,'held-refund:'.$contact['uuid']);$item=current($order->get_items('line_item'));wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'50.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'50.00','refund_tax'=>[]]],'refund_payment'=>false]);$this->reconcile(wc_get_order($order->get_id()));self::assertSame('released',$this->database->get('loyalty_holds',$hold['uuid'])['state']);self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['held']);self::assertSame('50',$points->balance($contact['uuid'],$program['uuid'])['balance']);
    }
    public function testExpiredEarnCannotResurrectAndRefundDoesNotCreateUnusedPointDebt():void
    {
        $user=wp_create_user('expired-'.bin2hex(random_bytes(5)),wp_generate_password(30),'expired-'.bin2hex(random_bytes(5)).'@example.test');$contact=$this->contact(true,$user);$program=$this->program();$order=$this->order($user);$points=$this->services['programs'];$this->reconcile($order);$lot=$this->database->list('loyalty_lots',['profile_id'=>$this->database->get('profiles',$contact['uuid'])['id'],'program_id'=>$program['id']],1)[0];$this->database->update('loyalty_lots',$lot['uuid'],['expires_at'=>gmdate('Y-m-d H:i:s',time()-60)]);$points->expire();self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['balance']);$this->reconcile($order);self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['balance'],'Paid-fact replay cannot resurrect expired earn.');
        $item=current($order->get_items('line_item'));wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'50.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'50.00','refund_tax'=>[]]],'refund_payment'=>false]);$this->reconcile(wc_get_order($order->get_id()));self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['balance'],'Unused expired points never become refund debt.');self::assertSame(0,(int)$this->database->get('loyalty_lots',$lot['uuid'])['remaining']);
    }
    public function testExpiryAfterPartialRefundDoesNotForgeEarnCorrection():void
    {
        $user=wp_create_user('lateexpiry-'.bin2hex(random_bytes(5)),wp_generate_password(30),'lateexpiry-'.bin2hex(random_bytes(5)).'@example.test');$contact=$this->contact(true,$user);$program=$this->program();$order=$this->order($user);$points=$this->services['programs'];$this->reconcile($order);$item=current($order->get_items('line_item'));wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'50.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'50.00','refund_tax'=>[]]],'refund_payment'=>false]);$order=wc_get_order($order->get_id());$this->reconcile($order);self::assertSame('50',$points->balance($contact['uuid'],$program['uuid'])['balance']);$lot=$this->database->list('loyalty_lots',['profile_id'=>$this->database->get('profiles',$contact['uuid'])['id'],'program_id'=>$program['id']],1)[0];$this->database->update('loyalty_lots',$lot['uuid'],['expires_at'=>gmdate('Y-m-d H:i:s',time()-60)]);$points->expire();self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['balance']);$this->reconcile($order);self::assertSame('0',$points->balance($contact['uuid'],$program['uuid'])['balance']);
    }
    public function testRefundDeletionAndRecreationCompensateEveryTransitionExactlyOnce():void
    {
        $user=wp_create_user('oscillation-'.bin2hex(random_bytes(5)),wp_generate_password(30),'oscillation-'.bin2hex(random_bytes(5)).'@example.test');$contact=$this->contact(true,$user);$affiliate=$this->contact();$loyalty=$this->program();$program=$this->program(['type'=>'affiliate','rate_bps'=>2500,'hold_days'=>0]);$order=$this->order($user);$points=$this->services['programs'];$this->reconcile($order);$commission=$points->recordCommission($program['uuid'],$affiliate['uuid'],$order->get_id(),10000,'USD','oscillation-commission:'.$order->get_id());$item=current($order->get_items('line_item'));
        for($round=0;$round<2;++$round){
            $refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'25.00','line_items'=>[$item->get_id()=>['qty'=>0,'refund_total'=>'25.00','refund_tax'=>[]]],'refund_payment'=>false]);self::assertInstanceOf(\WC_Order_Refund::class,$refund);$order=wc_get_order($order->get_id());$this->reconcile($order);self::assertSame('75',$points->balance($contact['uuid'],$loyalty['uuid'])['balance']);$this->reconcile($order);self::assertSame('75',$points->balance($contact['uuid'],$loyalty['uuid'])['balance']);
            $refund->delete(true);wc_delete_shop_order_transients($order->get_id());$order=wc_get_order($order->get_id());$order->set_status('processing');$order->save();$this->reconcile($order);self::assertSame('100',$points->balance($contact['uuid'],$loyalty['uuid'])['balance']);$this->reconcile($order);self::assertSame('100',$points->balance($contact['uuid'],$loyalty['uuid'])['balance']);
        }
        $raw=$this->database->get('commissions',$commission['uuid']);$corrections=$this->database->list('commissions',['reverses_id'=>$raw['id']],100);self::assertCount(4,$corrections);self::assertSame(0,array_sum(array_column($corrections,'amount_minor')));self::assertSame(2500,(int)$raw['amount_minor']);
    }
    public function testErasureCoversRecordedMergeAliasesAndRemovesRecoveryProof():void
    {
        $source=$this->contact();$target=$this->contact();$this->grant($source);$this->grant($target);$merge=$this->services['contacts']->merge($source['uuid'],$target['uuid'],'staff private note',['verification_reference'=>'proof-ref'],(int)$source['row_version'],(int)$target['row_version']);$export=$this->services['privacy']->export($target['uuid']);self::assertCount(4,array_filter($export['records'],static fn(array $row):bool=>$row['group']==='consents'));$job=$this->services['privacy']->erase($target['uuid']);for($i=0;$i<60&&$this->services['privacy']->job($job['uuid'])['state']!=='completed';++$i){$this->services['privacy']->process([],['privacy_uuid'=>$job['uuid']]);}self::assertSame('completed',$this->services['privacy']->job($job['uuid'])['state']);self::assertSame('erased',$this->database->get('profiles',$source['uuid'])['state']);$row=$this->database->get('identity_merges',$merge['uuid']);self::assertSame('',$row['proof']);self::assertSame('',$row['snapshot']);self::assertSame('Subject erased',$row['reason']);self::assertCount(0,$this->database->list('consents',['profile_id'=>$this->database->get('profiles',$source['uuid'])['id']]));
    }
    public function testNativeCouponUsesWooCrudAndReconcilesIssuance():void
    {
        $contact=$this->contact();$row=$this->services['definitions']->create('promotion','Coupon fixture',['discount_type'=>'fixed_cart','amount_minor'=>1234,'currency'=>get_woocommerce_currency(),'exponent'=>2,'usage_limit'=>1]);$this->services['definitions']->publish($row['uuid'],(int)$row['row_version']);$issued=$this->services['promotions']->issue($row['uuid'],$contact['uuid'],'once');self::assertSame($issued,$this->services['promotions']->issue($row['uuid'],$contact['uuid'],'once'));$coupon=new \WC_Coupon((int)$issued['coupon_id']);self::assertSame('12.34',$coupon->get_amount());self::assertSame([$contact['email']],$coupon->get_email_restrictions());self::assertTrue($this->services['promotions']->valid(true,$coupon));$definition=$this->database->get('definitions',$row['uuid']);$this->services['definitions']->transition($row['uuid'],'paused',(int)$definition['row_version']);self::assertFalse($this->services['promotions']->valid(true,$coupon));
    }
    public function testPrivacyExportUsesIndependentCursorsAndEraseFencesConsent():void
    {
        $contact=$this->contact();$this->grant($contact);$raw=$this->database->get('profiles',$contact['uuid']);for($i=0;$i<101;++$i){$this->database->insert('events',['name'=>'fixture','source'=>'privacy_test','source_key'=>hash('sha256',$contact['uuid'].':'.$i),'profile_id'=>$raw['id'],'properties'=>'{}','context'=>'{}','occurred_at'=>Database::now()]);}
        $page=$this->services['privacy']->export($contact['uuid']);self::assertFalse($page['done']);$second=$this->services['privacy']->export($contact['uuid'],$page['next_cursors']);self::assertTrue($second['done']);self::assertCount(1,$second['records']);$job=$this->services['privacy']->erase($contact['uuid']);self::assertFalse($this->services['consent']->allowed($contact['uuid'],'marketing','email'));for($i=0;$i<30 && $this->services['privacy']->job($job['uuid'])['state']!=='completed';++$i){$this->services['privacy']->process([],['privacy_uuid'=>$job['uuid']]);}self::assertSame('completed',$this->services['privacy']->job($job['uuid'])['state']);$erased=$this->database->get('profiles',$contact['uuid']);self::assertSame('erased',$erased['state']);self::assertNull($erased['email_cipher']);self::assertCount(0,$this->database->list('identities',['profile_id'=>$raw['id']]));self::assertNotEmpty($this->database->list('suppressions',['profile_id'=>$raw['id']]));
    }
    public function testIdentityMergeAndUnmergeRestoreProvenanceButNoConsent():void
    {
        $source=$this->contact();$target=$this->contact();$this->grant($source);$this->grant($target);$merge=$this->services['contacts']->merge($source['uuid'],$target['uuid'],'Same person verified',['verification_reference'=>'staff-owned-evidence-fixture'],(int)$source['row_version'],(int)$target['row_version']);self::assertSame('merged',$this->database->get('profiles',$source['uuid'])['state']);self::assertFalse($this->services['consent']->allowed($target['uuid'],'marketing','email'));$restore=$this->services['contacts']->unmerge($merge['uuid'],'Correction');self::assertSame('unmerged',$restore['state']);self::assertSame($source['email'],$this->services['contacts']->get($source['uuid'])['email']);self::assertFalse($this->services['consent']->allowed($source['uuid'],'marketing','email'));
    }
    public function testRegisteredPrincipalTransfersToMergeSurvivorAndReturnsOnUnmerge():void
    {
        $user=wp_create_user('merged-user-'.bin2hex(random_bytes(5)),wp_generate_password(30),'merged-user-'.bin2hex(random_bytes(5)).'@example.test');$source=$this->contact(true,$user);$target=$this->contact();$this->grant($source);$this->grant($target);
        $merge=$this->services['contacts']->merge($source['uuid'],$target['uuid'],'Ownership verified',['verification_reference'=>'registered-principal-proof'],(int)$source['row_version'],(int)$target['row_version']);
        self::assertNull($this->database->get('profiles',$source['uuid'])['user_id']);self::assertSame($target['uuid'],$this->database->find('profiles','user_id',$user)['uuid']);self::assertSame($user,(int)$this->database->get('profiles',$target['uuid'])['user_id']);self::assertSame($target['uuid'],$this->services['contacts']->create($source['email'],$user,true)['uuid']);self::assertFalse($this->services['consent']->allowed($target['uuid'],'marketing','email'));
        $this->services['contacts']->unmerge($merge['uuid'],'Restore original subject');self::assertSame($source['uuid'],$this->database->find('profiles','user_id',$user)['uuid']);self::assertNull($this->database->get('profiles',$target['uuid'])['user_id']);self::assertSame('unmerged',$this->services['contacts']->unmerge($merge['uuid'],'Idempotent repeat')['state']);self::assertFalse($this->services['consent']->allowed($source['uuid'],'marketing','email'));
    }
    public function testWordPressPrivacyResolvesMovedEmailThroughoutPaginatedErasure():void
    {
        $source=$this->contact();$target=$this->contact();$this->grant($source);$this->grant($target);$merge=$this->services['contacts']->merge($source['uuid'],$target['uuid'],'Privacy alias fixture',['verification_reference'=>'exact-alias-proof'],(int)$source['row_version'],(int)$target['row_version']);
        $export=$this->services['privacy']->wordpressExport($source['email'],1);self::assertNotEmpty($export['data']);self::assertCount(4,array_filter($export['data'],static fn(array $item):bool=>$item['group_id']==='wmos-consents'));
        $first=$this->services['privacy']->wordpressErase($source['email'],1);self::assertFalse($first['done']);self::assertTrue($first['items_removed']);self::assertCount(0,$this->database->list('identities',['profile_id'=>$this->database->get('profiles',$target['uuid'])['id']]));
        $second=$this->services['privacy']->wordpressErase($source['email'],2);self::assertFalse($second['done'],'Removing identities must not make WordPress prematurely report completion.');self::assertTrue($second['items_removed']);
        $result=$second;for($page=3;$page<=60&&!$result['done'];++$page){$result=$this->services['privacy']->wordpressErase($source['email'],$page);}self::assertTrue($result['done']);self::assertSame('erased',$this->database->get('profiles',$source['uuid'])['state']);self::assertSame('erased',$this->database->get('profiles',$target['uuid'])['state']);self::assertSame('',$this->database->get('identity_merges',$merge['uuid'])['snapshot']);
    }
    public function testDoubleOptInVerifiesOnlyThePinnedChallengeAddressAfterMerge():void
    {
        $source=$this->contact(false);$target=$this->contact(false);$this->services['contacts']->merge($source['uuid'],$target['uuid'],'Challenge alias fixture',['verification_reference'=>'verified-merge-reference'],(int)$source['row_version'],(int)$target['row_version']);$challenge=$this->services['consent']->requestDoubleOptIn($target['uuid'],'marketing','email','challenge-v1',['explicit'=>true],'primary-only');$job=$this->database->find('jobs','operation_key',hash('sha256','consent.confirmation:confirmation:'.$challenge['uuid']));$payload=json_decode($job['payload'],true,16,JSON_THROW_ON_ERROR);$plain=json_decode((new Secrets())->decrypt($payload['cipher'],'confirmation:'.$challenge['uuid']),true,16,JSON_THROW_ON_ERROR);
        self::assertSame($target['email'],$plain['email']);self::assertStringNotContainsString($target['email'],$job['payload']);$destination=null;$capture=static function($short,array $attributes)use(&$destination){$destination=$attributes['to'];return true;};add_filter('pre_wp_mail',$capture,99,2);try{$this->services['messaging']->sendConfirmation([], $payload);}finally{remove_filter('pre_wp_mail',$capture,99);}self::assertSame($target['email'],$destination);
        $this->services['consent']->confirm($plain['token']);$identities=$this->services['contacts']->exportIdentities($target['uuid']);$byAddress=array_column($identities,'verified_at','value');self::assertNotNull($byAddress[$target['email']]);self::assertNull($byAddress[$source['email']]);self::assertSame($target['email'],$this->services['contacts']->destination($this->database->get('profiles',$target['uuid']),'email'));self::assertTrue($this->services['consent']->allowed($target['uuid'],'marketing','email'));
    }
}
final class RecordingProvider implements Provider
{
    public int $calls=0;public array $content=[];public function __construct(private string $outcome='accepted'){}
    public function capabilities():array{return ['channels'=>['email'],'idempotency_seconds'=>0,'templates'=>false,'receipts'=>false];}
    public function submit(string $channel,string $destination,array $content,array $configuration,array $secrets,string $operationKey):ProviderOutcome{++$this->calls;$this->content=$content;return new ProviderOutcome($this->outcome,$this->outcome==='accepted'?'fixture-'.Database::uuid():null,$this->outcome==='ambiguous'?'unknown':null);}
}
