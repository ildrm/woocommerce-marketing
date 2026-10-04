<?php
declare(strict_types=1);
namespace Wmos\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Wmos\Infrastructure\Providers\WebhookVerifier;
final class WebhookVerifierTest extends TestCase
{
    public function testTwilioSignatureBindsCanonicalUrlAndEveryFormField():void
    {
        $url='https://store.example/wp-json/wmos/v1/provider/a';$form=['AccountSid'=>'AC'.str_repeat('a',32),'MessageSid'=>'SM123','MessageStatus'=>'delivered'];$sorted=$form;ksort($sorted);$text=$url;foreach($sorted as $k=>$v){$text.=$k.$v;}
        $signature=base64_encode(hash_hmac('sha1',$text,'secret',true));$result=WebhookVerifier::verify('twilio',http_build_query($form),['X-Twilio-Signature'=>$signature],['token'=>'secret'],['account_sid'=>$form['AccountSid']],$url);self::assertSame($form,$result['payload']);
        $this->expectException(\RuntimeException::class);WebhookVerifier::verify('twilio',http_build_query($form),['X-Twilio-Signature'=>$signature],['token'=>'secret'],['account_sid'=>$form['AccountSid']],$url.'?altered=1');
    }
    public function testResendSvixChecksTimestampAndExactRawBytes():void
    {
        $raw='{"type":"email.delivered","data":{"email_id":"msg1"}}';$ts=(string)time();$secret=str_repeat('s',32);$sig=base64_encode(hash_hmac('sha256','evt1.'.$ts.'.'.$raw,$secret,true));
        $headers=['svix-id'=>'evt1','svix-timestamp'=>$ts,'svix-signature'=>'v1,'.$sig];$result=WebhookVerifier::verify('resend',$raw,$headers,['webhook_secret'=>'whsec_'.base64_encode($secret)],[],'');self::assertSame('email.delivered',$result['payload']['type']);
        $this->expectException(\RuntimeException::class);WebhookVerifier::verify('resend',$raw.' ',$headers,['webhook_secret'=>'whsec_'.base64_encode($secret)],[],'');
    }
    public function testMetaAndTelegramFailClosed():void
    {
        $raw='{"entry":[]}';$sig='sha256='.hash_hmac('sha256',$raw,'meta-secret');self::assertIsArray(WebhookVerifier::verify('meta',$raw,['X-Hub-Signature-256'=>$sig],['webhook_secret'=>'meta-secret'],[],''));
        $this->expectException(\RuntimeException::class);WebhookVerifier::verify('telegram','{"update_id":1}',['X-Telegram-Bot-Api-Secret-Token'=>'wrong'],['webhook_secret'=>'right'],[],'');
    }
}
