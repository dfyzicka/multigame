<?php
/**
 * Inline Games - Telegram Bot (@inlinegamesbot)
 *
 * (c) Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Bot\Entity;

use Bot\Exception\BotException;
use Bot\Helper\Debug;
use Bot\Helper\Language;
use Bot\Manager\Game as GameManager;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;

/**
 * Class Game
 *
 * The "master" class for all games, contains shared methods
 *
 * @package Bot\Entity
 */
class Game
{
    /**
     * Game data
     *
     * @var mixed
     */
    protected $data;

    /**
     * List of languages
     *
     * @var mixed
     */
    protected $languages;

    /**
     * Game Manager object
     *
     * @var GameManager
     */
    protected $manager;

    /**
     * Game constructor.
     *
     * @param GameManager $manager
     */
    public function __construct(GameManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Handle game action
     *
     * @param $action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     *
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    public function handleAction($action)
    {
        if (class_exists($storage = $this->manager->getStorage()) && empty($this->data)) {
            Debug::isEnabled() && Debug::print('Reading game data from database');
            /** @var \Bot\Storage\Database\MySQL $storage */
            $this->data = $storage::selectFromGame($this->manager->getId());
        }

        if (!$this->data && !is_array($this->data)) {
            return $this->returnStorageFailure();
        }

        $data_before = $this->data;
        $action = strtolower(preg_replace("/[^a-zA-Z]+/", "", $action));
        $action = $action . 'Action';

        if (!method_exists($this, $action)) {
            Debug::isEnabled() && Debug::print('Method \'' . $action . '\' doesn\'t exist');

            return $this->answerCallbackQuery();
        }

        $this->languages = Language::list();

        if (isset($this->data['settings']['language']) && $language = $this->data['settings']['language']) {
            Language::set($language);
            Debug::isEnabled() && Debug::print('Set language: ' . $language);
        } else {
            Language::set(Language::getDefaultLanguage());
        }

        Debug::isEnabled() && Debug::print('Executing: ' . $action);

        $result = $this->$action();

        if ($result instanceof ServerResponse) {
            if ($result->isOk() || $this->strposa($result->getDescription(), $this->allowedAPIErrors) !== false) {
                Debug::isEnabled() && Debug::print('Server response is ok');
                $this->answerCallbackQuery();

                return $result;
            }

            Debug::isEnabled() && Debug::print('Server response is not ok');
            Debug::isEnabled() && Debug::print($result->getErrorCode() . ': ' . $result->getDescription());

            $this->answerCallbackQuery(__('Telegram API error!') . PHP_EOL . PHP_EOL . __("Try again in a few seconds."), true);

            throw new BotException('Telegram API error: ' . $result->getErrorCode() . ': ' . $result->getDescription());
        }

        Debug::isEnabled() && Debug::print('CRASHED');

        TelegramLog::error(
            $this->crashDump(
                [
                    'Game'               => $this->manager->getGame()::getTitle(),
                    'Game data (before)' => json_encode($data_before),
                    'Game data (after)'  => json_encode($this->data),
                    'Callback data'      => $this->manager->getUpdate()->getCallbackQuery() ? $this->manager->getUpdate()->getCallbackQuery()->getData() : '<not a callback query>',
                    'Result'             => $result,
                ],
                $this->manager->getId()
            )
        );

        if ($this->saveData([])) {
            $this->editMessage('<i>' . __("This game session has crashed.") . '</i>' . PHP_EOL . '(ID: ' . $this->manager->getId() . ')', $this->getReplyMarkup('empty'));
        }

        return $this->answerCallbackQuery(__('Critical error!', true));
    }

    /**
     * Save game data
     *
     * @param  $data
     *
     * @return bool
     *
     * @throws BotException
     * @throws \Bot\Exception\StorageException
     */
    protected function saveData($data): bool
    {
        Debug::isEnabled() && Debug::print('Saving game data to database');
        $data['game_code'] = $this->manager->getGame()::getCode();    // make sure we have the game code in the data array for /clean command!

        /** @var \Bot\Storage\Database\MySQL $storage */
        $storage = $this->manager->getStorage();

        return $storage::insertToGame($this->manager->getId(), $data);
    }

    /**
     * Answer to callback query helper
     *
     * @param string $text
     * @param bool $alert
     *
     * @return ServerResponse|mixed
     *
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function answerCallbackQuery($text = '', $alert = false)
    {
        if ($callback_query = $this->manager->getUpdate()->getCallbackQuery()) {
            return Request::answerCallbackQuery(
                [
                    'callback_query_id' => $callback_query->getId(),
                    'text'              => $text,
                    'show_alert'        => $alert,
                ]
            );
        }

        return Request::emptyResponse();
    }

    /**
     * Edit message helper
     *
     * @param $text
     * @param $reply_markup
     *
     * @return ServerResponse|mixed
     */
    protected function editMessage($text, $reply_markup)
    {
        return Request::editMessageText(
            [
                'inline_message_id'        => $this->manager->getId(),
                'text'                     => '<b>' . $this->manager->getGame()::getTitle() . '</b>' . PHP_EOL . PHP_EOL . $text,
                'reply_markup'             => $reply_markup,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );
    }

    /**
     * Returns notice about storage failure (error while saving mostly)
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function returnStorageFailure()
    {
        Debug::isEnabled() && Debug::print('Storage failure');

        return $this->answerCallbackQuery(__('Database error!') . PHP_EOL . PHP_EOL . __("Try again in a few seconds."), true);
    }

    /**
     * Get player user object
     *
     * @param $user
     * @param bool $as_json
     *
     * @return bool|User
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function getUser($user, $as_json = false)
    {
        Debug::isEnabled() && Debug::print($user . ' (as_json: ' . ($as_json ? 'true' : 'false') . ')');

        if ($as_json) {
            $result = isset($this->data['players'][$user]['id']) ? $this->data['players'][$user] : false;

            Debug::isEnabled() && Debug::print('JSON: ' . json_encode($result));

            return $result;
        }

        $result = isset($this->data['players'][$user]['id']) ? new User($this->data['players'][$user]) : false;

        Debug::isEnabled() && Debug::print((($result instanceof User) ? 'OBJ->JSON: ' . $result->toJson() : 'false'));

        return $result;
    }

    /**
     * Get current user object
     *
     * @param bool $as_json
     *
     * @return User|mixed
     *
     * @throws BotException
     */
    protected function getCurrentUser($as_json = false)
    {
        Debug::isEnabled() && Debug::print('(as_json: ' . ($as_json ? 'true' : 'false') . ')');

        if ($callback_query = $this->manager->getUpdate()->getCallbackQuery()) {
            $update_object = $callback_query;
        } elseif ($chosen_inline_result = $this->manager->getUpdate()->getChosenInlineResult()) {
            $update_object = $chosen_inline_result;
        } else {
            throw new BotException('No current user found!?');
        }

        if ($as_json) {
            $json = $update_object->getFrom();

            Debug::isEnabled() && Debug::print('JSON: ' . $json);

            return json_decode($json, true);
        }

        $result = $update_object->getFrom();

        Debug::isEnabled() && Debug::print((($result instanceof User) ? 'OBJ->JSON: ' . $result->toJson() : 'false'));

        return $result;
    }

    /**
     * Get user id safely (prevent getId() on null)
     *
     * @param $user
     *
     * @return bool|int
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function getUserId($user)
    {
        return $this->getUser($user) ? $this->getUser($user)->getId() : false;
    }

    /**
     * Get current user id safely (prevent getId() on null)
     *
     * @return bool|int
     *
     * @throws BotException
     */
    protected function getCurrentUserId()
    {
        return $this->getCurrentUser() ? $this->getCurrentUser()->getId() : false;
    }

    /**
     * Get specific user mention (host or guest)
     *
     * @param $user
     *
     * @return bool|int
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function getUserMention($user)
    {
        return $this->getUser($user) ? '<a href="tg://user?id=' . $this->getUser($user)->getId() . '">' . htmlentities($this->getUser($user)->getFirstName()) . '</a>' : false;
    }

    /**
     * Get current user mention
     *
     * @return bool|int
     *
     * @throws BotException
     */
    protected function getCurrentUserMention()
    {
        return $this->getCurrentUser() ? '<a href="tg://user?id=' . $this->getCurrentUser()->getId() . '">' . htmlentities($this->getCurrentUser()->getFirstName()) . '</a>' : false;
    }

    /**
     * Handle 'new' game action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    protected function newAction()
    {
        if ($this->getUser('host') && $this->getCurrentUserId() != $this->getCurrentUserId()) {
            return $this->answerCallbackQuery(__('This game is already created!'), true);
        }

        $this->data['players']['host'] = $this->getCurrentUser(true);
        $this->data['players']['guest'] = null;
        $this->data['data'] = null;

        if ($this->saveData($this->data)) {
            return $this->editMessage(__('{PLAYER_HOST} is waiting for opponent to join...', ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to join.', ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
        } else {
            return $this->returnStorageFailure();
        }
    }

    /**
     * Handle 'join' game action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    protected function joinAction()
    {
        if (!$this->getUser('host')) {
            Debug::isEnabled() && Debug::print('Host:' . $this->getCurrentUserMention());

            $this->data['players']['host'] = $this->getCurrentUser(true);

            if ($this->saveData($this->data)) {
                return $this->editMessage(__('{PLAYER_HOST} is waiting for opponent to join...', ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to join.', ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
            } else {
                return $this->returnStorageFailure();
            }
        } elseif (!$this->getUser('guest')) {
            if ($this->getCurrentUserId() != $this->getUserId('host') || (getenv('DEBUG') && $this->getCurrentUserId() == getenv('BOT_ADMIN'))) {
                Debug::isEnabled() && Debug::print('Guest:' . $this->getCurrentUserMention());

                $this->data['players']['guest'] = $this->getCurrentUser(true);

                if ($this->saveData($this->data)) {
                    return $this->editMessage(__('{PLAYER_GUEST} joined...', ['{PLAYER_GUEST}' => $this->getUserMention('guest')]) . PHP_EOL . __('Waiting for {PLAYER} to start...', ['{PLAYER}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to start.', ['{BUTTON}' => '<b>\'' . __('Play') . '\'</b>']), $this->getReplyMarkup('pregame'));
                } else {
                    return $this->returnStorageFailure();
                }
            } else {
                return $this->answerCallbackQuery(__("You cannot play with yourself!"), true);
            }
        } else {
            return $this->answerCallbackQuery(__("This game is full!"));
        }
    }

    /**
     * Handle 'quit' game action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    protected function quitAction()
    {
        if ($this->getCurrentUserId() !== $this->getUserId('host') && $this->getCurrentUserId() !== $this->getUserId('guest')) {
            return $this->answerCallbackQuery(__("You're not in this game!"), true);
        }

        if ($this->getUser('host') && $this->getCurrentUserId() == $this->getUserId('host')) {
            if ($this->getUser('guest')) {
                Debug::isEnabled() && Debug::print('Quit, host migration: ' . $this->getCurrentUserMention() . ' => ' . $this->getUserMention('guest'));

                $this->data['players']['host'] = $this->data['players']['guest'];
                $this->data['players']['guest'] = null;

                if ($this->saveData($this->data)) {
                    return $this->editMessage(__('{PLAYER} quit...', ['{PLAYER}' => $this->getCurrentUserMention()]) . PHP_EOL . __("{PLAYER_HOST} is waiting for opponent to join...", ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __("Press {BUTTON} button to join.", ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
                } else {
                    return $this->returnStorageFailure();
                }
            } else {
                Debug::isEnabled() && Debug::print('Quit (host): ' . $this->getCurrentUserMention());

                $this->data['players']['host'] = null;

                if ($this->saveData($this->data)) {
                    return $this->editMessage('<i>' . __("This game session is empty.") . '</i>', $this->getReplyMarkup('empty'));
                } else {
                    return $this->returnStorageFailure();
                }
            }
        } elseif ($this->getUser('guest') && $this->getCurrentUserId() == $this->getUserId('guest')) {
            Debug::isEnabled() && Debug::print('Quit (guest): ' . $this->getCurrentUserMention());

            $this->data['players']['guest'] = null;

            if ($this->saveData($this->data)) {
                return $this->editMessage('<i>' . __("This game session is empty.") . '</i>', $this->getReplyMarkup('empty'));
            } else {
                return $this->returnStorageFailure();
            }
        } else {
            Debug::isEnabled() && Debug::print('User quitting an empty game?');

            return $this->answerCallbackQuery();
        }
    }

    /**
     * Handle 'kick' game action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    protected function kickAction()
    {
        if ($this->getCurrentUserId() !== $this->getUserId('host')) {
            return $this->answerCallbackQuery(__("You're not the host!"), true);
        }

        if ($this->getUserId('host')) {
            Debug::isEnabled() && Debug::print($this->getCurrentUserMention() . ' kicked ' . $this->getUserMention('guest'));

            $user = $this->getUserMention('guest');
            $this->data['players']['guest'] = null;

            if ($this->saveData($this->data)) {
                return $this->editMessage(__('{PLAYER_GUEST} was kicked...', ['{PLAYER_GUEST}' => $user]) . PHP_EOL . __("{PLAYER_HOST} is waiting for opponent to join...", ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __("Press {BUTTON} button to join.", ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
            } else {
                return $this->returnStorageFailure();
            }
        } else {
            Debug::isEnabled() && Debug::print('Kick executed on a game without a host');

            return $this->answerCallbackQuery();
        }
    }

    /**
     * Handle 'start' game action
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function startAction()
    {
        if (!$this->getUser('host')) {
            $this->editMessage('<i>' . __("This game session is empty.") . '</i>', $this->getReplyMarkup('empty'));

            return $this->answerCallbackQuery();
        }

        if ($this->getCurrentUserId() !== $this->getUserId('host') && $this->getCurrentUserId() !== $this->getUserId('guest')) {
            return $this->answerCallbackQuery(__("You're not in this game!"), true);
        }

        if ($this->getCurrentUserId() !== $this->getUserId('host')) {
            return $this->answerCallbackQuery(__("You're not the host!"), true);
        }

        if (!$this->getUser('host') || !$this->getUser('guest')) {
            Debug::isEnabled() && Debug::print('Received request to start the game but one of the players wasn\'t in the game');

            return $this->answerCallbackQuery();
        }

        $this->data['data'] = [];

        Debug::isEnabled() && Debug::print($this->getCurrentUserMention());

        $result = $this->gameAction();

        return $result;
    }

    /**
     * Handle the game action
     *
     * This is just a dummy function.
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function gameAction()
    {
        if ($this->getCurrentUserId() !== $this->getUserId('host') && $this->getCurrentUserId() !== $this->getUserId('guest')) {
            return $this->answerCallbackQuery(__("You're not in this game!"), true);
        }

        return $this->answerCallbackQuery();
    }

    /**
     * Change language
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \Bot\Exception\StorageException
     */
    protected function languageAction()
    {
        $current_languge = Language::getCurrentLanguage();

        $this->languages;

        $selected_language = $this->languages[0];

        $picknext = false;
        foreach ($this->languages as $language) {
            if ($picknext) {
                $selected_language = $language;
                break;
            }

            if ($language == $current_languge) {
                $picknext = true;
            }
        }

        $this->data['settings']['language'] = $selected_language;

        if ($this->saveData($this->data)) {
            Debug::isEnabled() && Debug::print('Set language: ' . $selected_language);
            Language::set($selected_language);
        }

        if ($this->getUser('host') && !$this->getUser('guest')) {
            $this->editMessage(__('{PLAYER_HOST} is waiting for opponent to join...', ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to join.', ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
        } elseif ($this->getUser('host') && $this->getUser('guest')) {
            $this->editMessage(__('{PLAYER_GUEST} joined...', ['{PLAYER_GUEST}' => $this->getUserMention('guest')]) . PHP_EOL . __('Waiting for {PLAYER} to start...', ['{PLAYER}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to start.', ['{BUTTON}' => '<b>\'' . __('Play') . '\'</b>']), $this->getReplyMarkup('pregame'));
        }

        return $this->answerCallbackQuery();
    }

    /**
     * This will force a crash
     *
     * @return ServerResponse|mixed
     *
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function crashAction()
    {
        if (!getenv('DEBUG')) {
            return $this->answerCallbackQuery();
        }

        return '(forced crash)';
    }

    /**
     * Get specified reply markup
     *
     * @param string $keyboard
     *
     * @return InlineKeyboard
     *
     * @throws BotException
     */
    protected function getReplyMarkup($keyboard = '')
    {
        if (empty($keyboard)) {
            $keyboard = 'empty';
        }

        $keyboard = strtolower(preg_replace("/[^a-zA-Z]+/", "", $keyboard));
        $keyboard = $keyboard . 'Keyboard';

        Debug::isEnabled() && Debug::print($keyboard);

        if (!method_exists($this, $keyboard)) {
            Debug::isEnabled() && Debug::print('Method \'' . $keyboard . '\ doesn\'t exist');
            $keyboard = 'emptyKeyboard';
        }

        $keyboard = $this->$keyboard();

        if (getenv('DEBUG')) {
            $keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text'          => 'DEBUG: ' . 'CRASH',
                        'callback_data' => $this->manager->getGame()::getCode() . ';crash',
                    ]
                ),
            ];
        }

        $inline_keyboard_markup = new InlineKeyboard(...$keyboard);

        return $inline_keyboard_markup;
    }

    /**
     * Game empty keyboard
     *
     * @return array
     */
    protected function emptyKeyboard()
    {
        return [
            [
                new InlineKeyboardButton(
                    [
                        'text'          => __('Create'),
                        'callback_data' => $this->manager->getGame()::getCode() . ';new',
                    ]
                ),
            ],
        ];
    }

    /**
     * Game in lobby without guest keyboard
     *
     * @return array
     */
    protected function lobbyKeyboard()
    {
        $inline_keyboard = [];

        if (count($this->languages) > 1) {
            $inline_keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text'          => ucfirst(locale_get_display_language(Language::getCurrentLanguage(), Language::getCurrentLanguage())),
                        'callback_data' => $this->manager->getGame()::getCode() . ";language",
                    ]
                ),
            ];
        }

        $inline_keyboard[] = [
            new InlineKeyboardButton(
                [
                    'text'          => __('Quit'),
                    'callback_data' => $this->manager->getGame()::getCode() . ";quit",
                ]
            ),
            new InlineKeyboardButton(
                [
                    'text'          => __('Join'),
                    'callback_data' => $this->manager->getGame()::getCode() . ";join",
                ]
            ),
        ];

        return $inline_keyboard;
    }

    /**
     * Game in lobby with guest keyboard
     *
     * @return array
     */
    protected function pregameKeyboard()
    {
        $inline_keyboard = [];

        $inline_keyboard[] = [
            new InlineKeyboardButton(
                [
                    'text'          => __('Play'),
                    'callback_data' => $this->manager->getGame()::getCode() . ";start",
                ]
            ),
        ];

        if (count($this->languages) > 1) {
            $inline_keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text'          => ucfirst(locale_get_display_language(Language::getCurrentLanguage(), Language::getCurrentLanguage())),
                        'callback_data' => $this->manager->getGame()::getCode() . ";language",
                    ]
                ),
            ];
        }

        $inline_keyboard[] = [
            new InlineKeyboardButton(
                [
                    'text'          => __('Quit'),
                    'callback_data' => $this->manager->getGame()::getCode() . ";quit",
                ]
            ),
            new InlineKeyboardButton(
                [
                    'text'          => __('Kick'),
                    'callback_data' => $this->manager->getGame()::getCode() . ";kick",
                ]
            ),
        ];

        return $inline_keyboard;
    }

    /**
     * Keyboard for game in progress
     *
     * @param array $board
     * @param string $winner
     *
     * @return bool|InlineKeyboard
     *
     * @throws BotException
     */
    protected function gameKeyboard($board, $winner = '')
    {
        if (!isset($this->max_x) && !isset($this->max_y) && !isset($this->symbols)) {
            return false;
        }

        for ($x = 0; $x <= $this->max_x; $x++) {
            $tmp_array = [];

            for ($y = 0; $y <= $this->max_y; $y++) {
                if (isset($board[$x][$y])) {
                    if ($board[$x][$y] == 'X' || $board[$x][$y] == 'O' || strpos($board[$x][$y], 'won')) {
                        if ($winner == 'X' && $board[$x][$y] == 'O') {
                            $field = $this->symbols[$board[$x][$y] . '_lost'];
                        } elseif ($winner == 'O' && $board[$x][$y] == 'X') {
                            $field = $this->symbols[$board[$x][$y] . '_lost'];
                        } elseif (isset($this->symbols[$board[$x][$y]])) {
                            $field = $this->symbols[$board[$x][$y]];
                        }
                    } else {
                        $field = ($this->symbols['empty']) ?: ' ';
                    }

                    array_push(
                        $tmp_array,
                        new InlineKeyboardButton(
                            [
                                'text'          => $field,
                                'callback_data' => $this->manager->getGame()::getCode() . ';game;' . $x . '-' . $y,
                            ]
                        )
                    );
                }
            }

            if (!empty($tmp_array)) {
                $inline_keyboard[] = $tmp_array;
            }
        }

        if (!empty($winner)) {
            $inline_keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text'          => __('Play again!'),
                        'callback_data' => $this->manager->getGame()::getCode() . ';start',
                    ]
                ),
            ];
        }

        $inline_keyboard[] = [
            new InlineKeyboardButton(
                [
                    'text'          => __('Quit'),
                    'callback_data' => $this->manager->getGame()::getCode() . ';quit',
                ]
            ),
            new InlineKeyboardButton(
                [
                    'text'          => __('Kick'),
                    'callback_data' => $this->manager->getGame()::getCode() . ';kick',
                ]
            ),
        ];

        if (getenv('DEBUG')) {
            $this->boardPrint($board);

            $inline_keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text'          => 'DEBUG: ' . 'Restart',
                        'callback_data' => $this->manager->getGame()::getCode() . ';start',
                    ]
                ),
            ];
        }

        $inline_keyboard_markup = new InlineKeyboard(...$inline_keyboard);

        return $inline_keyboard_markup;
    }

    /**
     * Debug print of game board
     *
     * @param $board
     *
     * @throws BotException
     */
    protected function boardPrint($board)
    {
        if (!empty($board) && is_array($board) && isset($this->max_y) && isset($this->max_x)) {
            $board_out = str_repeat(' ---', $this->max_x) . PHP_EOL;

            for ($x = 0; $x < $this->max_x; $x++) {
                $line = '';

                for ($y = 0; $y < $this->max_y; $y++) {
                    $line .= '|' . (!empty($board[$x][$y]) ? ' ' . $board[$x][$y] . ' ' : '   ');
                }

                $board_out .= $line . '|' . PHP_EOL;
                $board_out .= str_repeat(' ---', $this->max_x) . PHP_EOL;
            }

            Debug::isEnabled() && Debug::print('CURRENT BOARD:' . PHP_EOL . $board_out);
        }
    }

    /**
     * Make a debug dump of crashed game session
     *
     * @param array $data
     *
     * @param  string $id
     * @return string
     */
    private function crashDump($data = [], $id = '')
    {
        $output = 'CRASH' . (isset($id) ? ' (ID: ' . $id . ')' : '') . ':' . PHP_EOL;
        foreach ($data as $var => $val) {
            $output .= $var . ': ' . (is_array($val) ? print_r($val, true) : (is_bool($val) ? ($val ? 'true' : 'false') : $val)) . PHP_EOL;
        }

        return $output;
    }

    /**
     * Handle a case when game data is empty but received a game action request
     *
     * @return ServerResponse|mixed
     *
     * @throws BotException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function handleEmptyData()
    {
        Debug::isEnabled() && Debug::print('Empty game data');

        if ($this->getUser('host') && !$this->getUser('guest')) {
            $this->editMessage(__('{PLAYER_HOST} is waiting for opponent to join...', ['{PLAYER_HOST}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to join.', ['{BUTTON}' => '<b>\'' . __('Join') . '\'</b>']), $this->getReplyMarkup('lobby'));
        } elseif ($this->getUser('host') && $this->getUser('guest')) {
            $this->editMessage(__('{PLAYER_GUEST} joined...', ['{PLAYER_GUEST}' => $this->getUserMention('guest')]) . PHP_EOL . __('Waiting for {PLAYER} to start...', ['{PLAYER}' => $this->getUserMention('host')]) . PHP_EOL . __('Press {BUTTON} button to start.', ['{BUTTON}' => '<b>\'' . __('Play') . '\'</b>']), $this->getReplyMarkup('pregame'));
        } else {
            $this->editMessage('<i>' . __("This game session is empty.") . '</i>', $this->getReplyMarkup('empty'));
        }

        return $this->answerCallbackQuery(__('Error!'), true);
    }

    /**
     * strpos() with array needle
     * https://stackoverflow.com/a/9220624
     *
     * @param $haystack
     * @param $needle
     * @param int $offset
     *
     * @return bool|mixed
     */
    private function strposa($haystack, $needle, $offset = 0)
    {
        if (!is_array($needle)) $needle = [$needle];
        foreach ($needle as $query) {
            if (strpos($haystack, $query, $offset) !== false) return true; // stop on first true result
        }

        return false;
    }
}
