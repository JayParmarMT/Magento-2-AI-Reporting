<?php
/**
 * Meetanshi AIReporting
 *
 * The AI replied in plain text instead of SQL because the question is not about data stored in the
 * database (a greeting, general Magento advice, or something this store has no table for).
 * Chat shows the reply as the answer; Ask AI shows it as a message.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Exception;

class DirectAnswerException extends LlmException
{
    public function getAnswer(): string
    {
        return $this->getMessage();
    }
}
