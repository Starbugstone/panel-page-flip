<?php

declare(strict_types=1);

namespace App\Service;

/** The retention sweep reached an account that has since stopped qualifying. */
final class AccountNoLongerEligible extends \DomainException
{
}
