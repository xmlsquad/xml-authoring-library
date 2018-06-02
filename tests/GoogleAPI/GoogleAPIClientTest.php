<?php

namespace Forikal\Library\Tests\GoogleAPI;

use Forikal\Library\GoogleAPI\GoogleAPIClient;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoogleAPIClientTest extends TestCase
{
    /**
     * @var \Google_Client|\PHPUnit\Framework\MockObject\MockObject Google API client mock. The original class methods
     *     are never called
     */
    protected $googleClientMock;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    protected function setUp()
    {
        parent::setUp();

        $this->googleClientMock = $this
            ->getMockBuilder('Google_Client')
            ->disableOriginalConstructor()
            ->disableProxyingToOriginalMethods()
            ->getMock();
        $this->googleClientMock->method('setApplicationName')->with('Forikal Tools');
        $this->googleClientMock->method('setAccessType')->with('offline');

        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Taken from https://phpunit.de/manual/4.8/en/test-doubles.html#test-doubles.mocking-the-filesystem
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('google'));
    }

    public function testAuthenticateWithoutSecretFile()
    {
        $this->googleClientMock->method('setScopes')->with([]);

        $secretPath = vfsStream::url('google/secret.json');
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('The `'.$secretPath.'` file doesn\'t exist');
        $client = new GoogleAPIClient($this->googleClientMock);
        $client->authenticate($secretPath, null, [], function () {});
    }

    public function testAuthenticateWithNotExistingAccessToken()
    {
        $secretPath = vfsStream::url('google/secret.json');
        $tokenPath = vfsStream::url('google/token.json');
        file_put_contents($secretPath, '{"secret": "qwerty"}');

        $this->googleClientMock->method('setScopes')->with([\Google_Service_Drive::DRIVE_READONLY]);
        $this->googleClientMock->method('setAuthConfig')->with(['secret' => 'qwerty']);
        $this->googleClientMock->method('createAuthUrl')->willReturn('https://google.com/auth');
        $this->googleClientMock->method('fetchAccessTokenWithAuthCode')->with('foo1')->willReturn(['token' => '9892ll']);
        $this->googleClientMock->method('isAccessTokenExpired')->willReturn(false);

        $this->loggerMock->method('info')->withConsecutive(
            ['Getting the Google API client secret from the `'.$secretPath.'` file'],
            ['Sending the authentication code to Google'],
            ['Saving the access token to the `'.$tokenPath.'` file, so subsequent executions will not prompt for authorization'],
            ['The Google authentication is completed']
        );
        $this->loggerMock->method('notice')->with('Authenticated successfully');

        $client = new GoogleAPIClient($this->googleClientMock, $this->loggerMock);
        $client->authenticate(
            $secretPath,
            $tokenPath,
            [\Google_Service_Drive::DRIVE_READONLY],
            function ($url) {
                $this->assertEquals('https://google.com/auth', $url);
                return 'foo1';
            }
        );
        $this->assertFileExists($tokenPath);
        $this->assertEquals(['token' => '9892ll'], json_decode(file_get_contents($tokenPath), true), 'Created JSON token file is incorrect');
    }

    public function testAuthenticateWithExistingAccessTokenAndRefresh()
    {
        $secretPath = vfsStream::url('google/secret.json');
        $tokenPath = vfsStream::url('google/token.json');
        file_put_contents($secretPath, '{"secret": "qwerty"}');
        file_put_contents($tokenPath, '{"token": ")*F)SD*&"}');

        $this->googleClientMock->method('setScopes')->with([]);
        $this->googleClientMock->method('setAuthConfig')->with(['secret' => 'qwerty']);
        $this->googleClientMock->method('setAccessToken')->with(['token' => ')*F)SD*&']);
        $this->googleClientMock->method('isAccessTokenExpired')->willReturn(true);
        $this->googleClientMock->method('fetchAccessTokenWithRefreshToken')->willReturn(['token' => '15$sDAF']);

        $this->loggerMock->method('info')->withConsecutive(
            ['Getting the Google API client secret from the `'.$secretPath.'` file'],
            ['Getting the last Google API access token from the `'.$tokenPath.'` file'],
            ['The access token is expired; refreshing the token'],
            ['Saving the refreshed access token to the `'.$tokenPath.'` file'],
            ['The Google authentication is completed']
        );

        $client = new GoogleAPIClient($this->googleClientMock, $this->loggerMock);
        $client->authenticate(
            $secretPath,
            $tokenPath,
            [],
            function () {
                $this->fail('The auth code getter function must not be called');
            }
        );
        $this->assertEquals(['token' => '15$sDAF'], json_decode(file_get_contents($tokenPath), true), 'Created JSON token file is incorrect');
    }

    public function testAuthenticateWithoutAccessToken()
    {
        $secretPath = vfsStream::url('google/secret.json');
        file_put_contents($secretPath, '{"secret": "qwerty"}');

        $this->googleClientMock->method('setScopes')->with([]);
        $this->googleClientMock->method('setAuthConfig')->with(['secret' => 'qwerty']);
        $this->googleClientMock->method('createAuthUrl')->willReturn('https://google.com/auth');
        $this->googleClientMock->method('fetchAccessTokenWithAuthCode')->with('foo1')->willReturn(['token' => 'mfmfmse']);
        $this->googleClientMock->method('isAccessTokenExpired')->willReturn(false);

        $this->loggerMock->method('info')->withConsecutive(
            ['Getting the Google API client secret from the `'.$secretPath.'` file'],
            ['Sending the authentication code to Google'],
            ['The Google authentication is completed']
        );
        $this->loggerMock->method('notice')->with('Authenticated successfully');

        $client = new GoogleAPIClient($this->googleClientMock, $this->loggerMock);
        $client->authenticate($secretPath, null, [], function () { return 'foo1'; });
        $this->assertCount(1, vfsStreamWrapper::getRoot()->getChildren());
    }

    public function testForceAuthenticate()
    {
        $secretPath = vfsStream::url('google/secret.json');
        $tokenPath = vfsStream::url('google/token.json');
        file_put_contents($secretPath, '{"secret": "qwerty"}');
        file_put_contents($tokenPath, '{"token": "U23Slsd--4"}');

        $this->googleClientMock->method('setScopes')->with([]);
        $this->googleClientMock->method('setAuthConfig')->with(['secret' => 'qwerty']);
        $this->googleClientMock->method('createAuthUrl')->willReturn('https://google.com/auth');
        $this->googleClientMock->method('fetchAccessTokenWithAuthCode')->with('foo2')->willReturn(['token' => 'asdfWEew1']);
        $this->googleClientMock->method('isAccessTokenExpired')->willReturn(false);

        $this->loggerMock->method('info')->withConsecutive(
            ['Getting the Google API client secret from the `'.$secretPath.'` file'],
            ['Sending the authentication code to Google'],
            ['Saving the access token to the `'.$tokenPath.'` file, so subsequent executions will not prompt for authorization'],
            ['The Google authentication is completed']
        );

        $client = new GoogleAPIClient($this->googleClientMock, $this->loggerMock);
        $client->authenticate($secretPath, $tokenPath, [], function () { return 'foo2'; }, true);
        $this->assertEquals(['token' => 'asdfWEew1'], json_decode(file_get_contents($tokenPath), true), 'Created JSON token file is incorrect');
    }

    public function testFailAuthentication()
    {
        $secretPath = vfsStream::url('google/secret.json');
        $tokenPath = vfsStream::url('google/token.json');
        file_put_contents($secretPath, '{"secret": "qwerty"}');

        $this->googleClientMock->method('setScopes')->with([]);
        $this->googleClientMock->method('setAuthConfig')->with(['secret' => 'qwerty']);
        $this->googleClientMock->method('createAuthUrl')->willReturn('https://google.com/auth');
        $this->googleClientMock->method('fetchAccessTokenWithAuthCode')->with('foo1')->willReturn(['error' => 'test', 'error_description' => 'This is a test']);

        $this->loggerMock->method('info')->withConsecutive(
            ['Getting the Google API client secret from the `'.$secretPath.'` file'],
            ['Sending the authentication code to Google']
        );

        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Google has declined the auth code: This is a test');
        $client = new GoogleAPIClient($this->googleClientMock, $this->loggerMock);
        $client->authenticate($secretPath, $tokenPath, [], function () { return 'foo1'; });
        $this->assertFileNotExists($tokenPath);
    }

    /**
     * @dataProvider getServiceProvider
     */
    public function testGetService($property, $shouldExist, $expectedClass = null)
    {
        $client = new GoogleAPIClient($this->googleClientMock);

        if ($shouldExist) {
            $this->assertInstanceOf($expectedClass, $client->$property);
        } else {
            $this->expectException('LogicException');
            $client->$property;
        }
    }

    public function getServiceProvider()
    {
        return [
            ['driveService', true, 'Google_Service_Drive'],
            ['sheetsService', true, 'Google_Service_Sheets'],
            ['slidesService', true, 'Google_Service_Slides'],
            ['fooService', false],
            ['bar', false]
        ];
    }
}
