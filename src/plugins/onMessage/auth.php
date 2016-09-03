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
 * Class auth
 * @property Discord\Parts\Channel\Message message
 */
class auth
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
	public $corpRoles;
	public $allianceRoles;
    public $db;
    public $dbUser;
    public $dbPass;
    public $dbName;
    public $forceName;
    public $ssoUrl;
    public $nameEnforce;

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
		
		$this->corpRoles = $config["plugins"]["auth"]["corpRoles"];
		$this->allianceRoles = $config["plugins"]["auth"]["allianceRoles"];
        $this->nameEnforce = $config["plugins"]["auth"]["nameEnforce"];
        $this->ssoUrl = $config["plugins"]["auth"]["url"];
    }
    /**
     *
     */
    function tick()
    {
    }

    /**
     * @param $msgData
     * @param $message Discord\Parts\Channel\Message
     * @return null
     */
    function onMessage($msgData, Discord\Parts\Channel\Message $message)
    {
        $this->message = $message;
        $userID = $msgData["message"]["fromID"];
        $userName = $msgData["message"]["from"];
        $message = $msgData["message"]["message"];		
		$defaultRole = $this->config["plugins"]["auth"]["defaultRole"];
        $data = command($message, $this->information()["trigger"], $this->config["bot"]["trigger"]);
        if (isset($data["trigger"])) {
            if (isset($this->config["bot"]["primary"])) {
                $userID = $msgData["message"]["fromID"];
                $channelInfo = $this->message->getChannelAttribute();
                $guildID = $channelInfo[@guild_id];
                if ($guildID != $this->config["bot"]["primary"]) {
                    $this->message->reply("**Failure:** The auth code your attempting to use is for another discord server");
                    return null;
                }

            }
            $code = $data["messageString"];
            $result = selectPending($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);

            if (strlen($code) < 12) {
                $this->message->reply("Invalid Code, check " . $this->config["bot"]["trigger"] . "help auth for more info.");
                return null;
            }

            while ($rows = $result->fetch_assoc()) {
                $charid = (int) $rows['characterID'];
                $corpid = (int) $rows['corporationID'];
                $allianceid = (int) $rows['allianceID'];
                $url = "https://api.eveonline.com/eve/CharacterName.xml.aspx?ids=$charid";
                $xml = makeApiRequest($url);


                // We have an error, show it 
                if ($xml->error) {
                    $this->message->reply("**Failure:** Eve API error, please try again in a little while.");
                    return null;
                }

                if (!isset($xml->result->rowset->row)) {
                    $this->message->reply("**Failure:** Eve API error, please try again in a little while.");
                    return null;
                } 							
				
                foreach ($xml->result->rowset->row as $character) {
                    $eveName = $character->attributes()->name;
                    $roles = $this->message->getChannelAttribute()->getGuildAttribute()->roles;
					$members = $this->message->getChannelAttribute()->getGuildAttribute()->members;
					$members->fetch($userID)->then(function (Discord\Parts\User\Member $member) use ($roles, $xml, $charid, $corpid, $allianceid, $message, $data, $userID, $userName, $eveName, $code, $members, $defaultRole) {
						$grantedRoles = array();
						$flag = 'false';
							foreach ($roles as $role) {
								$roleName = $role->name;
								if ($roleName == $defaultRole) {
									$member->addRole($role);
									array_push($grantedRoles, $role);
									if (!$flag) {
										$flag = 'true';
									}
									insertUser($this->db, $this->dbUser, $this->dbPass, $this->dbName, $userID, $charid, $eveName, 'ally');
									disableReg($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);
									break;
								}
							}
						if (array_key_exists($allianceid, $this->allianceRoles)) {
							foreach ($roles as $role) {
								$roleName = $role->name;
								if ($roleName == $this->allianceRoles[$allianceid]) {
									$member->addRole($role);
									array_push($grantedRoles, $role);
									if (!$flag) {
										$flag = 'true';
									}
									insertUser($this->db, $this->dbUser, $this->dbPass, $this->dbName, $userID, $charid, $eveName, 'ally');
									disableReg($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);
									break;
								}
							}
						}
						if (array_key_exists($corpid, $this->corpRoles)) {                        
							foreach ($roles as $role) {
								$roleName = $role->name;
								if ($roleName == $this->corpRoles[$corpid]) {
									array_push($grantedRoles, $role);
									$member->addRole($role);
									if (!$flag) {
										$flag = 'true';
										// Only insert new database entry for corp if no authorized alliance
										insertUser($this->db, $this->dbUser, $this->dbPass, $this->dbName, $userID, $charid, $eveName, 'corp');
										disableReg($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);
									}								
									break;
								}						
							}
						}
						if ($flag) {
							$reply = "**Success:** You have successfully been added to the following groups: ";
							$flag2 = 'false';
							foreach ($grantedRoles as $role) {
								if ($flag2) {
									$reply .= ", " . $role->name;
								} else {
									$reply .= $role->name;
									$flag2 = 'true';
								}
							}
							$this->message->reply($reply);
							$this->logger->addInfo("User successfully authed: " . $eveName);
						} else {
							$this->message->reply("**Failure:** There are no roles available for your corp/alliance.");
							$this->logger->addInfo("User was denied due to not being in the correct corp or alliance " . $eveName);
						}        
						if ($this->nameEnforce == 'true') {
							foreach ($xml->result->rowset->row as $character) {
								$member->setNickname((string) $character->attributes()->name)->then(function () use ($character) {
									$this->message->reply("Setting Nick " . $character->attributes()->name);
								})->otherwise(function (Exception $e) use ($character) {
                                     $this->message->reply("Error setting Nick " . $e->getMessage());
								});
								break;
							}
						}
						$members->save($member)->then(function () use ($character) {
						    $this->logger->addInfo("User successfully saved: " + $character);
                        })->otherwise(function (Exception $e) use ($character) {
                            $this->logger->addInfo("User " . $character ." failed to auth " . $e->getMessage());
                        });
					});                    										
					return null;
				}
			}
			$this->message->reply("**Failure:** There was an issue with your code.");
			$this->logger->addInfo("User was denied due to not being in the correct corp or alliance " . $userName);			
			return null;
		}
		return null;
    }
    /**
     * @return array
     */
    function information()
    {
        return array(
            "name" => "auth",
            "trigger" => array($this->config["bot"]["trigger"] . "auth"),
            "information" => "SSO based auth system. " . $this->ssoUrl . " Visit the link and login with your main EVE account, select the correct character, and put the !auth <string> you receive in chat."
        );
    }
    /**
     * @param $msgData
     */
    function onMessageAdmin($msgData)
    {
    }
}
