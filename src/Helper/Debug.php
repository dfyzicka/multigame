<?php
/**
 * Inline Games - Telegram Bot (@inlinegamesbot)
 *
 * (c) 2017 Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Bot\Helper;

use Bot\Exception\BotException;
use Longman\TelegramBot\TelegramLog;

/**
 * Class Debug
 *
 * @package Bot\Helper
 */
class Debug
{
    /**
     * Debug logging to either to TelegramLog's Debug log or console or both
     *
     * @param $text
     * @param null $prefix
     * @throws BotException
     */
    public static function log($text, $prefix = null): void
    {
        if ($text === '') {
            throw new BotException('Text cannot be empty!');
        }

        if (TelegramLog::isDebugLogActive() || getenv('Debug')) {
            if (!is_null($prefix)) {
                $prefix = $prefix . ': ';
            } else {
                $backtrace = debug_backtrace();

                if (isset($backtrace[1]['class'])) {
                    $prefix = $backtrace[1]['class'] . '\\' . $backtrace[1]['function'] . ': ';
                }
            }

            $message = $prefix . trim($text);
            $message = preg_replace('~[\r\n]+~', PHP_EOL . $prefix, $message);

            if (TelegramLog::isDebugLogActive()) {
                TelegramLog::Debug($message);
            }

            if (getenv('Debug')) {
                print($message . PHP_EOL);
            }
        }
    }

    /**
     * Make a debug dump
     *
     * @param $file_name
     * @param array $data
     *
     * @throws BotException
     */
    public static function dump($file_name, $data = []): void
    {
        if (empty($file_name)) {
            throw new BotException('File name cannot be empty!');
        }

        if (!is_dir(VAR_PATH . '/crashdumps/')) {
            mkdir(VAR_PATH . '/crashdumps/', 0755, true);
        }

        $output = '';

        $data['Backtrace'] = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

        foreach ($data as $var => $val) {
            $output .= $var . ':' . PHP_EOL . (is_array($val) ? print_r($val, true) : (is_bool($val) ? ($val ? 'true' : 'false') : $val)) . PHP_EOL . PHP_EOL;
        }

        file_put_contents(VAR_PATH . '/crashdumps/' . $file_name . '_' . date('y-m-d_H-i-s') . '.txt', $output);
    }
}
