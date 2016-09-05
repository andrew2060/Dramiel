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
 * @property  message
 */
class notify
{
    /**
     * @var
     */
    var $config;
    /**
     * @var
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
        $this->notifyChannel = $config["plugins"]["notify"]["channelID"];
    }
    /**
     *
     */
    function tick()
    {
    }


    function checkOpt($optName) {

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
        $sql = "SELECT * FROM 'subscription_groups' WHERE group = {$opt}";
        $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
        $rows = $result->fetch_row();
        if ($rows) { // Subscription group exists
            unset($msgArr[0]);
        } else {
            $sql = "SELECT * FROM 'subscription_groups'";
            $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
            $groups = array();
            while ($rows = $result->fetch_row()) {
                $groups[] = $rows["group"];
            }
            $this->message->reply("Unknown subscription group " . $opt . ". Available Subscription Groups: " . implode(", ", $groups));
            return null;
        }
        if ($rows["restricted"]) { // Restricted group (not everyone can notify)

        }
        $sql = "SELECT * FROM 'subscriptions' WHERE id = {$opt}";
        $result = submitNonUpdatingQuery($this->db, $this->dbUser, $this->dbPass, $this->dbName, $sql);
        $optArray = array();
        while ($rows = $result->fetch_row()) {
            $optArray[] = $rows["discordID"];
        }
        $messageString = implode(" ", $msgArr);
        $guild = $this->discord->guilds->first();
        foreach ($optArray as $optUsr) {
            $guild->members->fetch($optUsr)->then(function ($member) use ($userName, $opt, $messageString) {
                $member->user->sendMessage("[" . $userName . "|" . $opt . "] " . $messageString, false);
            });
        }

        return null;
    }

    /**
     * @return array
     */
    function information()
    {
        return array(
            "name" => "notify",
            "trigger" => array($this->config["bot"]["trigger"] . "notify"),
            "information" => "Notification Command: !notify subscription[default=eve] message. To change your active subscription groups use !subscriptions"
        );
    }
    /**
     * @param $msgData
     */
    function onMessageAdmin($msgData)
    {
    }
}
