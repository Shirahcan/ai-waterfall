<?php

namespace Shirahcan\AiWaterfall\Exceptions;

/**
 * A SENSITIVE task, and no credential is cleared to serve it.
 *
 * ⚠ This is a refusal, not an outage, and the distinction protects a client's
 * passport. Degrading to a non-compliant key here would send identity documents
 * or supplier invoices to a training tier. The fix is a human clearing a
 * credential deliberately (sensitive_ok), never a retry.
 */
class NoCompliantCredentialException extends \RuntimeException {}
