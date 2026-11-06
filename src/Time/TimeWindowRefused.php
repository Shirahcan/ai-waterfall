<?php

namespace Shirahcan\AiClient\Time;

use InvalidArgumentException;

/** A period the resolver cannot read. The message says why and lists the forms it accepts, for the model. */
final class TimeWindowRefused extends InvalidArgumentException {}
