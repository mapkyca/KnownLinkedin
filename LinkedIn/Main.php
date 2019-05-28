<?php

    namespace IdnoPlugins\LinkedIn {

        class Main extends \Idno\Common\Plugin
        {

            public static $AUTHORIZATION_ENDPOINT = 'https://www.linkedin.com/uas/oauth2/authorization';
            public static $TOKEN_ENDPOINT = 'https://www.linkedin.com/uas/oauth2/accessToken';
            public static $SHARE_URL = 'https://api.linkedin.com/v1/people/~/shares';

            public static $company = false;

            public static function getRedirectUrl()
            {
                return \Idno\Core\site()->config()->url . 'linkedin/callback';
            }

            public static function getState()
            {
                return md5(\Idno\Core\site()->config()->url . dirname(__FILE__));
            }

            function registerPages()
            {
                // Register the callback URL
                \Idno\Core\Idno::site()->routes()->addRoute('linkedin/callback', '\IdnoPlugins\LinkedIn\Pages\Callback');
                // Register admin settings
                \Idno\Core\Idno::site()->routes()->addRoute('admin/linkedin', '\IdnoPlugins\LinkedIn\Pages\Admin');
                // Register settings page
                \Idno\Core\Idno::site()->routes()->addRoute('account/linkedin', '\IdnoPlugins\LinkedIn\Pages\Account');
                // Deauth
                \Idno\Core\Idno::site()->routes()->addRoute('linkedin/deauth', '\IdnoPlugins\LinkedIn\Pages\Deauth');

                /** Template extensions */
                // Add menu items to account & administration screens
                \Idno\Core\site()->template()->extendTemplate('admin/menu/items', 'admin/linkedin/menu');
                \Idno\Core\site()->template()->extendTemplate('account/menu/items', 'account/linkedin/menu');
            }

            function registerEventHooks()
            {

                // Register syndication services
                \Idno\Core\site()->syndication()->registerService('linkedin', function () {
                    return $this->hasLinkedIn();
                }, ['note','article','image', 'bookmark']);
		
		\Idno\Core\Idno::site()->events()->addListener('user/auth/success', function (\Idno\Core\Event $event) {
		    if ($this->hasLinkedIn()) {
			if (is_array(\Idno\Core\site()->session()->currentUser()->linkedin)) { 
			    foreach (\Idno\Core\site()->session()->currentUser()->linkedin as $id => $details) {
				if ($id != 'access_token') {
				    \Idno\Core\site()->syndication()->registerServiceAccount('linkedin', $id, $details['name']);
				} else {
				    \Idno\Core\site()->syndication()->registerServiceAccount('linkedin', $id, 'LinkedIn');
				}
			    }
			}
		    }
		});

                // Push "notes" to LinkedIn
                \Idno\Core\Idno::site()->events()->addListener('post/note/linkedin', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();
                    $object    = $eventdata['object'];
                    if ($this->hasLinkedIn()) {
                        if ($linkedinAPI = $this->connect($eventdata['syndication_account'])) {
                            if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'])) {
                                $name = \Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'];
                            } else {
                                $name = 'LinkedIn';
                            }
                            $message = strip_tags($object->getDescription());
                            //$message .= "\n\n" . $object->getURL();
                            if (!empty($message) && substr($message, 0, 1) != '@') {

                                try {

                                    $result = \Idno\Core\Webservice::post(self::$SHARE_URL . '?oauth2_access_token=' . $linkedinAPI->access_token,
                                        '
<share>
<comment>' . htmlentities($message, ENT_XML1, 'UTF-8') . '</comment>
<visibility> 
<code>anyone</code> 
</visibility>
</share>
', [
                                            "Content-Type: application/xml",
                                        ]);

                                    if ($result['response'] == 201) {
                                        // Success
                                        $link = "";
                                        if (preg_match('/<update-url>(.*?)<\/update-url>/', $result['content'], $matches)) {
                                            $link = $matches[1];
                                        }

                                        $object->setPosseLink('linkedin', $link, $name);
                                        $object->save();

                                    } else {
                                        if (preg_match('/<message>(.*?)<\/message>/', $result['content'], $matches)) {
                                            $message = $matches[1];
                                        }

                                        \Idno\Core\site()->logging->log("LinkedIn Syndication: " . print_r($result, true), LOGLEVEL_ERROR);

                                        \Idno\Core\site()->session()->addErrorMessage("Linkedin returned error code: {$result['response']} - $message");
                                    }

                                } catch (\Exception $e) {
                                    \Idno\Core\site()->session()->addErrorMessage('There was a problem posting to LinkedIn: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                });

                // Push "articles" to LinkedIn
                \Idno\Core\Idno::site()->events()->addListener('post/article/linkedin', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();
                    $object    = $eventdata['object'];
                    if ($this->hasLinkedIn()) {
                        if ($linkedinAPI = $this->connect($eventdata['syndication_account'])) {

                            if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'])) {
                                $name = \Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'];
                            } else {
                                $name = 'LinkedIn';
                            }

                            $result = \Idno\Core\Webservice::post(self::$SHARE_URL . '?oauth2_access_token=' . $linkedinAPI->access_token,
                                '
<share>
<content>
<title>' . htmlentities(strip_tags($object->getTitle()), ENT_XML1, 'UTF-8') . '</title>
<submitted-url>' . htmlentities($object->getUrl(), ENT_XML1, 'UTF-8') . '</submitted-url>
</content>
<visibility> 
<code>anyone</code> 
</visibility>
</share>
', [
                                    "Content-Type: application/xml",
                                ]);


                            if ($result['response'] == 201) {
                                // Success
                                $link = "";
                                if (preg_match('/<update-url>(.*?)<\/update-url>/', $result['content'], $matches)) {
                                    $link = $matches[1];
                                }

                                $object->setPosseLink('linkedin', $link, $name);
                                $object->save();
                            } else {
                                if (preg_match('/<message>(.*?)<\/message>/', $result['content'], $matches)) {
                                    $message = $matches[1];
                                }
                                \Idno\Core\site()->session()->addErrorMessage("Linkedin returned error code: {$result['response']} - $message");
                                \Idno\Core\site()->logging->log("LinkedIn Syndication: " . print_r($result, true), LOGLEVEL_ERROR);
                            }

                        }
                    }
                });
		
		// Push "bookmark" to LinkedIn
                \Idno\Core\Idno::site()->events()->addListener('post/bookmark/linkedin', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();
                    $object    = $eventdata['object'];
                    if ($this->hasLinkedIn()) {
                        if ($linkedinAPI = $this->connect($eventdata['syndication_account'])) {

                            if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'])) {
                                $name = \Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'];
                            } else {
                                $name = 'LinkedIn';
                            }

                            $result = \Idno\Core\Webservice::post(self::$SHARE_URL . '?oauth2_access_token=' . $linkedinAPI->access_token,
                                '
<share>
<content>
<title>' . htmlentities($object->pageTitle ? $object->pageTitle : $object->body, ENT_XML1, 'UTF-8') . '</title>
<description>' . htmlentities($object->description, ENT_XML1, 'UTF-8') . '</description>
<submitted-url>' . htmlentities($object->body, ENT_XML1, 'UTF-8') . '</submitted-url>
</content>
<visibility> 
<code>anyone</code> 
</visibility>
</share>
', [
                                    "Content-Type: application/xml",
                                ]);


                            if ($result['response'] == 201) {
                                // Success
                                $link = "";
                                if (preg_match('/<update-url>(.*?)<\/update-url>/', $result['content'], $matches)) {
                                    $link = $matches[1];
                                }

                                $object->setPosseLink('linkedin', $link, $name);
                                $object->save();
                            } else {
                                if (preg_match('/<message>(.*?)<\/message>/', $result['content'], $matches)) {
                                    $message = $matches[1];
                                }
                                \Idno\Core\site()->session()->addErrorMessage("Linkedin returned error code: {$result['response']} - $message");
                                \Idno\Core\site()->logging->log("LinkedIn Syndication: " . print_r($result, true), LOGLEVEL_ERROR);
                            }

                        }
                    }
                });

                // Push "images" to LinkedIn
                \Idno\Core\Idno::site()->events()->addListener('post/image/linkedin', function (\Idno\Core\Event $event) {
                    $eventdata = $event->data();
                    $object    = $eventdata['object'];
                    if ($attachments = $object->getAttachments()) {
                        foreach ($attachments as $attachment) {
                            if ($this->hasLinkedIn()) {

                                if ($linkedinAPI = $this->connect($eventdata['syndication_account'])) {

                                    if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'])) {
                                        $name = \Idno\Core\site()->session()->currentUser()->linkedin[$eventdata['syndication_account']]['name'];
                                    } else {
                                        $name = 'LinkedIn';
                                    }

                                    $message = strip_tags($object->getDescription());
                                    $message .= "\n\nOriginal: " . $object->getURL();

                                    $result = \Idno\Core\Webservice::post(self::$SHARE_URL . '?oauth2_access_token=' . $linkedinAPI->access_token,
                                        '
	<share>
	<content>
	<title>' . htmlentities(strip_tags($object->getTitle()), ENT_XML1, 'UTF-8') . '</title>
	<description>' . htmlentities($message, ENT_XML1, 'UTF-8') . '</description>
	<submitted-url>' . htmlentities($object->getUrl(), ENT_XML1, 'UTF-8') . '</submitted-url>
	<submitted-image-url>' . $attachment['url'] . '</submitted-image-url>
	</content>
	<visibility> 
	<code>anyone</code> 
	</visibility>
	</share>
	', [
                                            "Content-Type: application/xml",
                                        ]);


                                    if ($result['response'] == 201) {
                                        // Success
                                        $link = "";
                                        if (preg_match('/<update-url>(.*?)<\/update-url>/', $result['content'], $matches)) {
                                            $link = $matches[1];
                                        }

                                        $object->setPosseLink('linkedin', $link, $name);
                                        $object->save();
                                    } else {
                                        if (preg_match('/<message>(.*?)<\/message>/', $result['content'], $matches)) {
                                            $message = $matches[1];
                                        }
                                        \Idno\Core\site()->session()->addErrorMessage("Linkedin returned error code: {$result['response']} - $message");
                                        \Idno\Core\site()->logging->log("LinkedIn Syndication: " . print_r($result, true), LOGLEVEL_ERROR);
                                    }

                                }
                            }
                        }
                    }
                });
            }

            /**
             * Connect to LinkedIn
             * @return bool|\LinkedIn
             */
            function connect($username = false)
            {
                if (!empty(\Idno\Core\site()->config()->linkedin)) {
                  
                    $linkedinAPI = new Client(
                        \Idno\Core\site()->config()->linkedin['appId'],
                        \Idno\Core\site()->config()->linkedin['secret']
                    );

                    if (empty($username)) {
                        if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin['access_token']) && ($username == 'LinkedIn' || empty($username))) {
                            $linkedinAPI->setAccessToken(\Idno\Core\site()->session()->currentUser()->linkedin['access_token']);
                        }
                    } else {

                        if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$username]['access_token'])) {
                            $linkedinAPI->setAccessToken(\Idno\Core\site()->session()->currentUser()->linkedin[$username]['access_token']);
                            if (!empty(\Idno\Core\site()->session()->currentUser()->linkedin[$username]['company'])) {
                                self::$company = true;
                                self::$SHARE_URL = 'https://api.linkedin.com/v1/companies/'.$username.'/shares';
                            }
                        }
                    }

                    return $linkedinAPI;
                }

                return false;
            }

            /**
             * Can the current user use Linkedin?
             * @return bool
             */
            function hasLinkedIn()
            {
                if (!(\Idno\Core\site()->session()->currentUser())) {
                    return false;
                }
                if (\Idno\Core\site()->session()->currentUser()->linkedin) {
                    return true;
                }

                return false;
            }

        }

    }
