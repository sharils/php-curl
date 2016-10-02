<?php

namespace Sharils;

use InvalidArgumentException;
use RuntimeException;
use stdClass;

class Phurl
{
    const EOL = "\r\n";

    const HEADER_BDOY = "\r\n\r\n";

    private $defaults = [];

    private $mh = null;

    public function __construct()
    {
        $this->mh = curl_multi_init();
    }

    public function __destruct()
    {
        if (is_resource($this->mh) && get_resource_type($this->mh) === 'curl') {
            curl_multi_close($this->mh);
        }
    }

    public function curl(array $optionsList, array $options = [])
    {
        return call_user_func_array(function (
            array $options,
            array $otherOptions = null
        ) {
            $curls = array_map([$this, 'opt2Curl'], func_get_args());

            $errors = $this->exec($curls);
            if (!empty($errors)) {
                throw new RuntimeException($this->err2Msg($errors));
            }

            return $curls;
        }, $optionsList);
    }

    public function parseResponse($curl)
    {
        $response = curl_multi_getcontent($curl);
        assert('is_string($response)');

        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        $body = substr($response, $size) ?: null;

        $header = substr($response, 0, $size);
        $fields = explode(self::EOL, $header);
        $fields = array_filter($fields, [$this, 'isField']);
        $header = array_reduce($fields, [$this, 'setField']);

        return [
            $body,
            (object) $header
        ];
    }

    public function setDefaults(array $defaults)
    {
        $this->defaults = $defaults;
    }

    public function setOptions(array $options)
    {
        foreach ($options as $option => $value) {
            $success = curl_multi_setopt($this->mh, $option, $value);
            assert('$success === true');
        }
    }

    public function share(array $options)
    {
        $sh = curl_share_init();

        foreach ($options as $value => $key) {
            $success = curl_share_setopt($sh, $key, $value);
            assert('$success');
        }

        return $sh;
    }

    private function err2Msg(array $errors)
    {
        ksort($errors);
        $message = [];
        foreach ($errors as $errorIdx => $error) {
            $message[] = $errorIdx . ': ' . curl_strerror($error);
        }
        $message = implode(PHP_EOL, $message);

        return $message;
    }

    private function exec(array $curls)
    {
        $errors = [];
        $re = null;

        foreach ($curls as $curl) {
            $error = curl_multi_add_handle($this->mh, $curl);
            assert('$error === CURLM_OK');
        }

        do {
            do {
                $mrc = curl_multi_exec($this->mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            if ($mrc !== CURLM_OK) {
                $re = new RuntimeException(curl_multi_strerror($mrc), $mrc);
                break;
            }

            do {
                $info = curl_multi_info_read($this->mh);
                if (isset($info['result']) && $info['result'] !== CURLE_OK) {
                    $errors[array_search($info['handle'], $curls)] =
                        $info['result'];
                }
            } while ($info);
        } while ($active && (~curl_multi_select($this->mh) || !usleep(100)));

        foreach ($curls as $curl) {
            $error = curl_multi_remove_handle($this->mh, $curl);
            assert('$error === CURLM_OK');
        }

        if ($re) {
            throw $re;
        }

        return $errors;
    }

    private function isField($text)
    {
        return strpos($text, ':');
    }

    private function offsetGet($offset)
    {
        return function ($matches) use ($offset) {
            return strtoupper($matches[$offset]);
        };
    }

    private function opt2Curl(array $options)
    {
        $curl = curl_init();

        $success = curl_setopt_array($curl, $options + $this->defaults);
        assert('$success');

        return $curl;
    }

    private function setField($header, $field)
    {
        list($key, $value) = preg_split('/:\s*/', $field, 2);
        $key = strtolower($key);
        $key = preg_replace_callback('/-(.)/', $this->offsetGet(1), $key);

        $header[$key] = $value;

        return $header;
    }
}
