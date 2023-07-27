<?php
namespace BotMan\Drivers\Web\Extras;

use BotMan\BotMan\Interfaces\WebAccess;
use BotMan\BotMan\Messages\Attachments\Attachment;

class AttachmentVisitorReply implements WebAccess
{
    /** @var string */
    protected $text;

    /** @var Attachment */
    protected $attachment;

    /**
     * AttachmentVisitorReply constructor.
     *
     * @param  string  $message
     * @param  Attachment  $attachment
     */
    public function __construct(string $message, Attachment $attachment)
    {
        $this->text = $message;
        $this->attachment = $attachment;
    }

    /**
     * Get the instance as a web accessible array.
     * This will be used within the WebDriver.
     *
     * @return array
     */
    public function toWebDriver()
    {
        return [
            'type' => 'text',
            'from' =>'visitor',
            'text' => $this->text,
            'attachment' => (is_null($this->attachment)) ? null : $this->attachment->toWebDriver()
        ];
    }
}