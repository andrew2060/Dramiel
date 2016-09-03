<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 Robert Sardinia
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use Discord\Helpers\Guzzle;

/**
 * Class notify
 * @property Discord\Parts\Channel\Message message
 */
class notify
{
    /**
     * @var
     */
    var $config;
    /**
     * @var \Discord\Discord
     */
    var $discord;
    /**
     * @var
     */
    var $logger;
    var $solarSystems;
    var $triggers = array();
    public $db;
    public $dbUser;
    public $dbPass;
    public $dbName;
    public $notifyChannel;

    /**
     * @param $config
     * @param $discord
     * @param $logger
     */
    function init($config, $discord, $logger)
    {
        $this->config = $config;
        $this->discord = $discord;
        $this->logger = $logger;
        $this->db = $config["database"]["host"];
        $this->dbUser = $config["database"]["user"];
        $this->dbPass = $config["database"]["pass"];
        $this->dbName = $config["database"]["database"];
    }

    /**
     *
     */
    function tick()
    {
    }

    /**
     * @param $msgData
     * @param $message
     * @return null
     */
    function onMessage($msgData, $message)
    {
        $this->message = $message;
        $userID = $msgData["message"]["fromID"];
        $userName = $msgData["message"]["from"];
        $message = $msgData["message"]["message"];
        $data = command($message, $this->information()["trigger"], $this->config["bot"]["trigger"]);
        $msgArr = $data["messageArray"];
        $opt = $msgArr[0];
        $sql = "SELECT * FROM 'authgroup_managers' WHERE group = {$opt} AND discordID = {$userID}";
        $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
        $rows = $result->fetch_row();
        if ($rows) { // Has the ability to manage group in question
            unset($msgArr[0]);
        } else {
            $this->message->reply("You lack the appropriate authorization to perform this action. This incident has been logged for further review");
            return null;
        }
        $msgArr = array_values($msgArr);
        $action = $msgArr[0];
        $flag = false;
        switch ($action) {
            case "add": {
                $flag = true;
                break;
            }
            case "remove": {
                $flag = false;
                break;
            }
            default: {
                $this->message->reply("Invalid action selected, please use one of [add|remove]");
                break;
            }
        }
        /* @var $mentions \Discord\Parts\User\User[] */
        $mentions = $this->message->getMentionsAttribute();
        foreach ($mentions as $user) {
            if ($flag) {
                $sql = "SELECT * FROM authgroup_roles WHERE group='{$opt}'";
                $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
                $row = $result->fetch_row();
                if ($row != null) {
                    $channelID = $row["channelID"];
                    $success = false;
                    while (!$success) {
                        $channelRepo = $this->message->getChannelAttribute()->getGuildAttribute()->channels;
                        $channelRepo->fetch($channelID)->then(
                            function ($channel) use ($row, $user, &$success, $opt) {
                                // Initialize perms
                                /* @var $permissions \Discord\Parts\Permissions\ChannelPermission */
                                $permissions = $this->discord->factory(\Discord\Parts\Permissions\ChannelPermission::class);
                                // Set perms
                                $permissions->decodeBitwise($row["allowPermMask"], $row["denyPermMask"]);
                                /* @var $channel Discord\Parts\Channel\Channel */
                                $channel->setPermissions($user, $permissions)->then(function () use (&$success, $user, $opt) {
                                    $this->message->reply($user->username . " was successfully added to " . $opt);
                                        $success = true;
                                    });
                            });
                    }
                    $sql = "INSERT INTO 'authgroup_members' (group, discordID) VALUES ('{$opt}', {$user->id})";
                    executeUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
                }
            } else {
                $sql = "SELECT * FROM authgroup_roles WHERE group='{$opt}'";
                $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
                $row = $result->fetch_row();
                if ($row != null) {
                    $channelID = $row["channelID"];
                    $success = false;
                    while (!$success) {
                        $channelRepo = $this->message->getChannelAttribute()->getGuildAttribute()->channels;
                        $channelRepo->fetch($channelID)->then(
                            function ($channel) use ($row, $user, &$success, $opt) {
                                // Initialize perms
                                /* @var $permissions \Discord\Parts\Permissions\ChannelPermission */
                                $permissions = $this->discord->factory(\Discord\Parts\Permissions\ChannelPermission::class);
                                // Set perms
                                $permissions->decodeBitwise(0, 0);
                                /* @var $channel Discord\Parts\Channel\Channel */
                                $channel->setPermissions($user, $permissions)->then(function () use (&$success, $user, $opt) {
                                    $this->message->reply($user->username . " was successfully removed from " . $opt);
                                    $success = true;
                                });
                            });
                    }
                    $sql = "DELETE FROM 'authgroup_members' WHERE group='{$opt}' AND discordID = {$user->id}";
                    executeUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
                }
            }

        }
        return null;
    }
}

/**
 * @return array
 */
function information()
{
    return array(
        "name" => "notify",
        "trigger" => array($this->config["bot"]["trigger"] . "group"),
        "information" => "Groups Command: !group <add|remove> name1 name2 ..."
    );
}

/**
 * @param $msgData
 */
function onMessageAdmin($msgData)
{
}
