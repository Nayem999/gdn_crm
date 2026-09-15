<?php

namespace App\Domain\Social\Enums;

/**
 * What kind of thing a message is.
 *
 * Kept short on purpose. Meta has a long vocabulary of attachment types and most
 * of the distinctions do not survive contact with an inbox — a customer sends a
 * photograph of a broken part and whether Meta called it `image` or `photo` is
 * not a difference anybody here acts on.
 *
 * `Unsupported` is the honest case for the rest: a sticker, a live location, a
 * product enquiry card. The thread says something arrived and what it was
 * called, rather than showing an empty bubble that reads as a bug.
 */
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case File = 'file';
    /**
     * An approved template, which is the only thing sendable once the window has
     * closed. Its own type because "what did we send outside the window" is a
     * question this has to answer.
     */
    case Template = 'template';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Message',
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Voice note',
            self::File => 'File',
            self::Template => 'Template',
            self::Unsupported => 'Attachment',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'lucide-message-square',
            self::Image => 'lucide-image',
            self::Video => 'lucide-video',
            self::Audio => 'lucide-mic',
            self::File => 'lucide-paperclip',
            self::Template => 'lucide-file-text',
            self::Unsupported => 'lucide-file-question',
        };
    }

    public function hasMedia(): bool
    {
        return match ($this) {
            self::Image, self::Video, self::Audio, self::File => true,
            default => false,
        };
    }

    /**
     * Meta's own attachment type, as ours.
     *
     * Anything unrecognised becomes `Unsupported` rather than `Text`: an empty
     * text bubble is indistinguishable from a bug, and a row saying "Attachment"
     * tells whoever is reading it to go and look at Messenger.
     */
    public static function fromMeta(?string $type): self
    {
        return match ($type) {
            'text' => self::Text,
            'image', 'photo' => self::Image,
            'video' => self::Video,
            'audio', 'voice' => self::Audio,
            'file', 'document' => self::File,
            'template' => self::Template,
            default => self::Unsupported,
        };
    }
}
