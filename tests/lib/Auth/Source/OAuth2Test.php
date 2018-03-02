<?php

namespace Test\SimpleSAML\Auth\Source;


use AspectMock\Test as test;
use CirrusIdentity\SSP\Test\Capture\RedirectException;
use CirrusIdentity\SSP\Test\MockHttp;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use SimpleSAML\Module\authoauth2\Auth\Source\OAuth2;
use Test\SimpleSAML\MockOAuth2Provider;

/**
 * Test authentication to OAuth2.
 */
class OAuth2Test extends \PHPUnit_Framework_TestCase
{

    public $module_config;

    public static function setUpBeforeClass()
    {
        putenv('SIMPLESAMLPHP_CONFIG_DIR=' . dirname(dirname(dirname(__DIR__))) . '/config');
    }

    protected function tearDown()
    {
        test::clean(); // remove all registered test doubles
    }

    public function testDefaultConfigItemsSet()
    {
        $info = ['AuthId' => 'oauth2'];
        $authOAuth2 = new OAuth2($info, []);

        $this->assertEquals('http://localhost/module.php/authoauth2/linkback.php',
            $authOAuth2->getConfig()->getString('redirectUri'));
        $this->assertEquals(3, $authOAuth2->getConfig()->getInteger('timeout'));

        $authOAuth2 = new OAuth2($info, ['redirectUri' => 'http://other', 'timeout' => 6]);
        $this->assertEquals('http://other', $authOAuth2->getConfig()->getString('redirectUri'));
        $this->assertEquals(6, $authOAuth2->getConfig()->getInteger('timeout'));

    }

    public function testConfigTemplateByName()
    {
        $info = ['AuthId' => 'oauth2'];
        $authOAuth2 = new OAuth2($info, [
            'template' => 'GoogleOIDC',
            'attributePrefix' => 'myPrefix.',
            'label' => 'override'
        ]);

        $expectedConfig = [
            'urlAuthorize' => 'https://accounts.google.com/o/oauth2/auth',
            'urlAccessToken' => 'https://accounts.google.com/o/oauth2/token',
            'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scopes' =>  array(
                'openid',
                'email',
                'profile'
            ),
            'scopeSeparator' => ' ',
            'attributePrefix' => 'myPrefix.',
            'label' => 'override',
            'template' => 'GoogleOIDC',
            'redirectUri' => 'http://localhost/module.php/authoauth2/linkback.php',
            'timeout' => 3,

        ];

        $this->assertEquals($expectedConfig, $authOAuth2->getConfig()->toArray());

    }

    public function testAuthenticatePerformsRedirect()
    {
        // Override redirect behavior
        MockHttp::throwOnRedirectTrustedURL();
        $info = ['AuthId' => 'oauth2'];
        $config = [
            'urlAuthorize' => 'https://example.com/auth',
            'urlAccessToken' => 'https://example.com/token',
            'urlResourceOwnerDetails' => 'https://example.com/userinfo'
        ];
        $state = [\SimpleSAML_Auth_State::ID => 'stateId'];

        $authOAuth2 = new OAuth2($info, $config);

        try {
            $authOAuth2->authenticate($state);
            $this->fail("Redirect expected");
        } catch (RedirectException $e) {
            $this->assertEquals('redirectTrustedURL', $e->getMessage());
            $this->assertEquals(
                'https://example.com/auth?state=authoauth2%7CstateId&response_type=code&approval_prompt=auto&redirect_uri=http%3A%2F%2Flocalhost%2Fmodule.php%2Fauthoauth2%2Flinkback.php',
                $e->getUrl(),
                "First argument should be the redirect url"
            );
            $this->assertEquals([], $e->getParams(), "query params are already added into url");
        }

        $this->assertEquals($state[OAuth2::AUTHID], 'oauth2', 'Ensure authsource name is presevered in state');
    }


    public function testFinalSteps()
    {
        // given: A mock Oauth2 provider
        $code = 'theCode';
        $info = ['AuthId' => 'oauth2'];
        $config = [
            'providerClass' => MockOAuth2Provider::class,
            'attributePrefix' => 'test.'
        ];
        $state = [\SimpleSAML_Auth_State::ID => 'stateId'];

        $mock = $this->getMockBuilder(AbstractProvider::class)
            ->disableOriginalConstructor()
            ->getMock();
        $token = new AccessToken(['access_token' => 'stubToken']);
        $mock->method('getAccessToken')
            ->with('authorization_code', ['code' => $code])
            ->willReturn($token);

        $attributes = ['name' => 'Bob'];
        $user = new GenericResourceOwner($attributes, 'userId');
        $mock->method('getResourceOwner')
            ->with($token)
            ->willReturn($user);

        MockOAuth2Provider::setDelegate($mock);

        // when: turning a code into a token and then into a resource owner attributes
        $authOAuth2 = new OAuth2($info, $config);
        $authOAuth2->finalStep($state, $code);

        // then: The attributes should be returned based on the getResourceOwner call
        $expectedAttributes = ['test.name' => ['Bob']];
        $this->assertEquals($expectedAttributes, $state['Attributes']);

    }

}