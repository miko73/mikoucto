<?php

namespace App\Modules\Partners\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class PartnerInput
{
    #[Assert\Length(max: 200)]
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    public ?string $name = null;
    #[Assert\Length(max: 32)]
    public ?string $registrationNumber = null;
    #[Assert\Regex('/^[A-Z]{2}[A-Z0-9]{2,30}$/D')]
    public ?string $vatNumber = null;
    #[Assert\Country]
    #[Assert\NotBlank(allowNull: true)]
    public ?string $countryCode = null;
    #[Assert\Length(max: 200)]
    public ?string $street = null;
    #[Assert\Length(max: 120)]
    public ?string $city = null;
    #[Assert\Length(max: 20)]
    public ?string $postalCode = null;
    #[Assert\Email]
    #[Assert\Length(max: 254)]
    public ?string $email = null;
    #[Assert\Length(max: 40)]
    public ?string $phone = null;
    public ?bool $active = null;
}
