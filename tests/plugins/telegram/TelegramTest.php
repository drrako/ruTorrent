<?php

require_once(__DIR__.'/../../../tests/php/TestCase.php');
require_once(__DIR__.'/../../../plugins/telegram/telegram.php');

class TelegramTest extends TestCase
{
	public function testDefaultsAndSecretsAreNotExported()
	{
		$config = new rTelegram();
		$config->token = '123456:super-secret';
		$export = $config->get();
		$this->assertTrue(strpos($export, 'super-secret') === false, 'the bot token is never exported to JavaScript');
		$this->assertTrue(strpos($export, 'tokenConfigured') !== false, 'the browser receives only token configuration status');
		$this->assertEquals(4, count(rTelegram::eventNames()), 'all initial event types are declared');
	}

	public function testInputAndTemplateRendering()
	{
		$config = new rTelegram();
		$config->updateFromInput(array(
			'enabled' => '1',
			'token' => ' token ',
			'chat_id' => '-100',
			'event_finished' => '1',
			'template_finished' => '{STATE}|{TORRENT}|{HASH}',
		));
		$this->assertTrue($config->enabled, 'enabled is parsed');
		$this->assertEquals('token', $config->token, 'token is trimmed');
		$this->assertTrue($config->events['finished'], 'selected events are parsed');
		$this->assertEquals('Finished|name "with" $danger|ABC', $config->render('finished', 'name "with" $danger', 'ABC'), 'placeholders are rendered as plain text');
		$this->assertTrue(!$config->events['added'], 'unchecked events stay disabled');
	}

	public function testAnEmptyTokenPreservesTheExistingSecret()
	{
		$config = new rTelegram();
		$config->token = 'keep-me';
		$config->updateFromInput(array('chat_id' => '1'));
		$this->assertEquals('keep-me', $config->token, 'the blank browser token does not erase the stored secret');
		$config->updateFromInput(array('token_clear' => '1'));
		$this->assertEquals('', $config->token, 'an explicit clear request erases the token');
	}

	public function testMessageLimitDoesNotSplitUtf8()
	{
		$message = rTelegram::truncateMessage(str_repeat('я', 5000));
		$this->assertTrue(strlen($message) <= rTelegram::MAX_TEMPLATE_LENGTH, 'messages are bounded to Telegram size');
		$this->assertTrue(preg_match('//u', $message) === 1, 'message truncation keeps valid UTF-8');
	}

	public function testClientAcceptsTelegramSuccess()
	{
		$client = new rTelegramClient(function($url, $payload, $options) {
			return array('http_code' => 200, 'body' => '{"ok":true,"result":{"message_id":1}}');
		});
		$result = $client->send('secret-token', '1', 'hello');
		$this->assertTrue($result['ok'], 'a successful Telegram response is accepted');
	}

	public function testClientRejectsFailuresWithoutLeakingToken()
	{
		$cases = array(
			array('http_code' => 401, 'body' => '{"ok":false,"description":"Unauthorized"}'),
			array('http_code' => 500, 'body' => 'server error'),
			array('http_code' => 200, 'body' => 'not json'),
			array('http_code' => 200, 'body' => '{"ok":false,"description":"bad token secret-token"}'),
		);
		foreach($cases as $case)
		{
			$client = new rTelegramClient(function($url, $payload, $options) use ($case) { return $case; });
			$result = $client->send('secret-token', '1', 'hello');
			$this->assertTrue(!$result['ok'], 'invalid API responses are rejected');
			$this->assertTrue(strpos(isset($result['error']) ? $result['error'] : '', 'secret-token') === false, 'API errors redact the bot token');
		}
	}

	public function testClientRejectsTransportExceptions()
	{
		$client = new rTelegramClient(function($url, $payload, $options) {
			throw new Exception('network secret-token failure');
		});
		$result = $client->send('secret-token', '1', 'hello');
		$this->assertTrue(!$result['ok'], 'transport exceptions become a failed result');
		$this->assertTrue(strpos($result['error'], 'secret-token') === false, 'transport errors redact the bot token');
	}

	public function testNotificationCommandUsesBackgroundArgumentPassing()
	{
		$_SERVER['REMOTE_USER'] = 'Test User';
		$config = new rTelegram();
		$command = $config->notificationCommand('finished');
		$this->assertTrue(strpos($command, 'execute.nothrow') !== false, 'notifications use execute.nothrow');
		$this->assertTrue(strpos($command, '\\$@') !== false, 'torrent data is passed through shell positional arguments');
		$this->assertTrue(strpos($command, 'notify.php') !== false, 'the background command launches notify.php');
	}
}
