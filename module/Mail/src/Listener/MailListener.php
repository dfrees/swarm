<?php
/**
 * Created by PhpStorm.
 * User: swellard
 * Date: 29/01/19
 * Time: 16:37
 */

namespace Mail\Listener;

use Events\Listener\AbstractEventListener;
use Mail\Module;
use Laminas\EventManager\Event;

class MailListener extends AbstractEventListener
{

    // send email notifications for task events that prepare mail data.
    // we use a very low priority so that others can influence the message.
    public function handleMail(Event $event)
    {
        if ($message = Module::buildMessage($event, $this->services)) {
            // This event is requesting an email, move on
            try {
                $mailer = $this->services->get('mailer');
                $mailer->send($message);

                // in debug mode, report the subject and recipient information
                $this->services->get('logger')->debug(
                    'Mail:Email sent. Subject: ' . $message->getSubject(),
                    [
                        'recipients' => array_keys(
                            array_map(
                                function ($address) {
                                    $address->getEmail();
                                },
                                array_merge(
                                    iterator_to_array($message->getTo()),
                                    iterator_to_array($message->getBcc())
                                )
                            )
                        )
                    ]
                );

                // if we have the option, disconnect to avoid timeouts
                // unit tests don't have this method so we have to gate the call
                if (method_exists($mailer, 'disconnect')) {
                    $mailer->disconnect();
                }
            } catch (\Exception $e) {
                $this->services->get('logger')->err($e);
            }
        }
    }
}
