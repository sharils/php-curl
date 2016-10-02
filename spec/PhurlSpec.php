<?php

namespace spec\Sharils;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Sharils\Phurl;

class PhurlSpec extends ObjectBehavior
{
    const CONTENT = 'Lorem ipsum dolor sit amet.';

    const NAME = 'Lorem';

    public function getMatchers()
    {
        static $prefix = 'should';

        $methods = get_class_methods($this);

        $methods = array_filter($methods, function ($method) use ($prefix) {
            return strpos($method, $prefix) === 0;
        });

        $matchers = [];
        $prefixLength = strlen($prefix);
        foreach ($methods as $method) {
            $key = substr($method, $prefixLength);
            $key = lcfirst($key);
            $matchers[$key] = [$this, $method];
        }

        return $matchers;
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType('Sharils\Phurl');
    }

    public function it_executes_parallel()
    {
        $begin = microtime(true);
        $this->curl(array_pad(
            [],
            5,
            $this->usleep(200000)
        ))->shouldFinishBefore($begin + 200000 * 5 / 1000000);
    }

    public function it_is_array_in_array_out()
    {
        $this->curl(array_pad(
            [],
            2,
            $this->blank()
        ))->shouldBeResourcesOf('curl');
    }

    public function it_sets_curl_resource_content()
    {
        $this->curl(array_pad(
            [],
            2,
            $this->printR(self::CONTENT)
        ))->shouldSetCurlResourceContent(self::CONTENT);
    }

    public function it_throws_error()
    {
        $this->shouldThrow('RuntimeException')->duringCurl([
            $this->blank(),
            [],
        ]);
    }

    public function it_reuses_connection()
    {
        $this->curl([$this->blank()]);

        $this->curl([$this->verbose($log)])->
            shouldReuseConnection($log);
    }

    public function it_parses_response()
    {
        list($curl) = $this->curl([$this->header(self::CONTENT)]);

        $this->parseResponse($curl)->shouldBeParsed([
            self::CONTENT,
            (object) [
                'contentLength' => (string) strlen(self::CONTENT),
                'contentType' => 'text/html; charset=UTF-8',
            ]
        ]);
    }

    public function it_converts_empty_body_to_null()
    {
        list($curl) = $this->curl([$this->header('')]);

        $this->parseResponse($curl)->shouldBeParsed([
            null,
            (object) [
                'contentLength' => '0',
                'contentType' => 'text/html; charset=UTF-8',
            ]
        ]);
    }

    public function it_honours_defaults()
    {
        $this->setDefaults($this->printR(self::CONTENT));

        $this->curl(array_pad(
            [],
            2,
            []
        ))->shouldSetCurlResourceContent(self::CONTENT);
    }

    public function it_tries_pipelining()
    {
        $this->setOptions([CURLMOPT_PIPELINING => 2]);

        $this->curl(array_pad([], 2, $this->verbose($log)))->
            shouldTryPipeling($log);
    }

    public function it_honours_share()
    {
        $sh = $this->share([
            CURL_LOCK_DATA_COOKIE => CURLSHOPT_SHARE
        ]);

        $this->curl([
            $this->cookie($sh, self::NAME, self::CONTENT)
        ]);

        $this->curl([
            $this->cookie($sh, self::NAME, self::CONTENT)
        ])->shouldSendCookie(self::NAME, self::CONTENT);
    }

    public function shouldBeParsed($subject, $parsed)
    {
        unset(
            $subject[1]->date,
            $subject[1]->server,
            $subject[1]->xPoweredBy
        );

        return $subject == $parsed;
    }

    public function shouldBeResourcesOf(array $subjects, $type)
    {
        $result = true;
        foreach ($subjects as $subject) {
            $result = $result &&
                is_resource($subject) &&
                get_resource_type($subject) === $type;
        }

        return $result;
    }

    public function shouldFinishBefore($curls, $end)
    {
        return microtime(true) < $end;
    }

    public function shouldReuseConnection($subject, $log)
    {
        rewind($log);
        $log = stream_get_contents($log);

        return strpos($log, '* Re-using existing connection!') !== false;
    }

    public function shouldSendCookie($subject, $name, $value)
    {
        $info = curl_getinfo($subject[0], CURLINFO_HEADER_OUT);

        $value = urlencode($value);

        return strpos($info, "Cookie: $name=$value");
    }

    public function shouldSetCurlResourceContent(array $subjects, $content)
    {
        $set = true;
        foreach ($subjects as $subject) {
            $set = $set && curl_multi_getcontent($subject) === $content;
        }

        return $set;
    }

    public function shouldTryPipeling($subject, $log)
    {
        rewind($log);
        $log = stream_get_contents($log);

        return strpos($log, '* Server doesn\'t support pipelining') !== false;
    }

    private function testServer($query = null)
    {
        if ($query !== null) {
            $param_arr = func_get_args();
            $callback = array_shift($param_arr);

            $query = '?' . http_build_query([
                $callback => $param_arr
            ]);
        }

        return 'http://' . TEST_SERVER . "/$query";
    }

    private function blank()
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $this->testServer()
        ];
    }

    private function cookie($sh, $name, $value)
    {
        return [
            CURLINFO_HEADER_OUT => true,
            CURLOPT_SHARE => $sh,
            CURLOPT_URL => $this->testServer('setcookie', $name, $value)
        ];
    }

    private function header($content)
    {
        return $this->printR($content) + [
            CURLOPT_HEADER => true
        ];
    }

    private function printR($content)
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $this->testServer('print_r', $content)
        ];
    }

    private function usleep($microSeconds)
    {
        return [
            CURLOPT_URL => $this->testServer('usleep', $microSeconds)
        ];
    }

    private function verbose(&$log)
    {
        return [
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $log = tmpfile(),
            CURLOPT_URL => $this->testServer()
        ];
    }
}
