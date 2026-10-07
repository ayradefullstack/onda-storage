<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

/**
 * A file or oeuvre cannot be removed right now. Always names the file —
 * "a file is still being processed" is useless when the deposit holds
 * nine of them.
 *
 * `reasonCode` is what the UI translates; `getMessage()` is the English
 * source string for the validation bag (this project has no server-side
 * `lang/`, see SubmissionReason). It is not called `code` because
 * `Exception::$code` already exists and is a readwrite int.
 */
final class RemovalRefused extends DepositException
{
    public const MID_PIPELINE = 'mid_pipeline';

    public const STILL_UPLOADING = 'still_uploading';

    private function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly string $fileName,
    ) {
        parent::__construct($message);
    }

    public static function midPipeline(string $fileName, string $status): self
    {
        return new self(
            "\"{$fileName}\" is still being processed ({$status}). Wait for it to finish, then remove it.",
            self::MID_PIPELINE,
            $fileName,
        );
    }

    public static function stillUploading(string $fileName): self
    {
        return new self(
            "\"{$fileName}\" is still uploading. Cancel the upload instead of removing it.",
            self::STILL_UPLOADING,
            $fileName,
        );
    }
}
