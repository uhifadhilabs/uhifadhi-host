<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The three things a person is: their sign-in email and their name. TIER and POSITION are
 * deliberately NOT form fields — they are rendered as the design's own selects (name="tier",
 * name="position"), exactly as the position screen renders its department, and the controller
 * reads them against the enum and the position repository.
 *
 * There is no password field: the app generates the password and shows it once.
 */
final class MemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(message: 'A member needs an email — it is how they sign in.'),
                    new Email(message: 'That is not an email address.'),
                    new Length(max: 180, maxMessage: 'Keep the email under {{ limit }} characters.'),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'constraints' => [
                    new NotBlank(message: 'A member needs a first name.'),
                    new Length(max: 100, maxMessage: 'Keep it under {{ limit }} characters.'),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'constraints' => [
                    new NotBlank(message: 'A member needs a last name.'),
                    new Length(max: 100, maxMessage: 'Keep it under {{ limit }} characters.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
