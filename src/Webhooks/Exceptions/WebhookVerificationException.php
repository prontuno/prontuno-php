<?php

declare(strict_types=1);

namespace Prontuno\Webhooks\Exceptions;

use Prontuno\Exceptions\ProntunoException;
use RuntimeException;

class WebhookVerificationException extends RuntimeException implements ProntunoException {}
