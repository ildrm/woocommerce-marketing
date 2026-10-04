<?php
declare(strict_types=1);
namespace Wmos\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Wmos\Infrastructure\Providers\HttpProvider;
final class ProviderTransportTest extends TestCase
{
    protected function setUp():void{if(getenv('WMOS_INTEGRATION')!=='1'){self::markTestSkipped('Requires isolated WordPress HTTP API fixture.');}}
    public function testConcreteProviderRequestsUseFixedEndpointsAndExactCredentials():void
    {
        $cases=[
            ['resend','email','buyer@example.test',['subject'=>'Subject','text'=>'Body'],['from'=>'sender@example.test'],'https://api.resend.com/emails',['id'=>'email-fixture']],
            ['twilio','sms','+15555550123',['text'=>'Body'],['account_sid'=>'AC'.str_repeat('a',32),'from'=>'+15555550124'],'https://api.twilio.com/2010-04-01/Accounts/AC'.str_repeat('a',32).'/Messages.json',['sid'=>'SM-fixture']],
            ['meta','whatsapp','+15555550123',['template'=>'approved_offer','language'=>'en_US'],['api_version'=>'v23.0','phone_number_id'=>'12345','approved_templates'=>['approved_offer']],'https://graph.facebook.com/v23.0/12345/messages',['messages'=>[['id'=>'wamid-fixture']]]],
            ['telegram','telegram','12345',['text'=>'Body'],[],'https://api.telegram.org/bot123:fixture/sendMessage',['ok'=>true,'result'=>['message_id'=>1]]],
            ['onesignal','push','subscription-fixture',['text'=>'Body','title'=>'Title'],['app_id'=>'11111111-1111-1111-1111-111111111111'],'https://api.onesignal.com/notifications',['id'=>'push-fixture']],
        ];
        foreach($cases as [$type,$channel,$destination,$content,$config,$url,$response]){
            $captured=[];$filter=static function($previous,array $args,string $requestUrl)use(&$captured,$response){$captured=[$requestUrl,$args];return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode($response,JSON_THROW_ON_ERROR)];};add_filter('pre_http_request',$filter,10,3);
            try{$outcome=(new HttpProvider($type))->submit($channel,$destination,$content,$config,['token'=>'123:fixture'],'stable-operation');}finally{remove_filter('pre_http_request',$filter,10);}
            self::assertSame('accepted',$outcome->state);self::assertSame($url,$captured[0]);self::assertSame(0,$captured[1]['redirection']);self::assertTrue($captured[1]['sslverify']);self::assertSame(15,$captured[1]['timeout']);
            if($type==='resend'){self::assertSame('stable-operation',$captured[1]['headers']['Idempotency-Key']);self::assertSame('Bearer 123:fixture',$captured[1]['headers']['Authorization']);self::assertSame([$destination],json_decode($captured[1]['body'],true)['to']);}
            if($type==='twilio'){parse_str($captured[1]['body'],$body);self::assertSame($destination,$body['To']);self::assertSame('application/x-www-form-urlencoded',$captured[1]['headers']['Content-Type']);}
            if($type==='meta'){self::assertSame('template',json_decode($captured[1]['body'],true)['type']);}
            if($type==='onesignal'){self::assertSame([$destination],json_decode($captured[1]['body'],true)['include_subscription_ids']);}
        }
    }
    public function testTransportUnknownIsAmbiguousAndRateLimitIsRetryable():void
    {
        foreach([0=>'ambiguous',429=>'retryable',503=>'ambiguous',401=>'rejected'] as $code=>$expected){$filter=static fn()=> $code===0?new \WP_Error('timeout','Simulated unknown outcome'):['response'=>['code'=>$code],'headers'=>['retry-after'=>'10'],'body'=>'{}'];add_filter('pre_http_request',$filter,10,3);try{$outcome=(new HttpProvider('resend'))->submit('email','test@example.test',['subject'=>'test','text'=>'body'],['from'=>'from@example.test'],['token'=>'fixture'],'key');}finally{remove_filter('pre_http_request',$filter,10);}self::assertSame($expected,$outcome->state);}
    }
    public function testWhatsAppTemplateMustBeInReviewedCatalog():void
    {
        $outcome=(new HttpProvider('meta'))->submit('whatsapp','+15555550123',['template'=>'unreviewed','language'=>'en_US'],['approved_templates'=>['reviewed']],['token'=>'fixture'],'key');self::assertSame('rejected',$outcome->state);self::assertSame('template_not_approved',$outcome->error);
    }
    public function testRelayCannotTargetUnapprovedOrPrivateAddress():void
    {
        $this->expectException(\InvalidArgumentException::class);HttpProvider::approvedRelay('https://127.0.0.1/admin');
    }
}
