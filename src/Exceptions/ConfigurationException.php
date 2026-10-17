<?php

declare(strict_types=1);

namespace Prontuno\Exceptions;

use InvalidArgumentException;

class ConfigurationException extends InvalidArgumentException implements ProntunoException {}
