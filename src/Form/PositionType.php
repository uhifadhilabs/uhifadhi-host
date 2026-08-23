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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Just the position's name. The permission matrix is deliberately NOT a form field — it is
 * rendered as manual survey-plate checkboxes (name="permissions[]") so it keeps the design
 * system, and the controller filters the submitted values against
 * {@see \Uhifadhi\Service\PermissionCatalogueService} (core + module-declared).
 */
final class PositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Name',
            'disabled' => (bool) $options['locked'],
            'constraints' => [
                new NotBlank(message: 'A position needs a name.'),
                new Length(max: 120, maxMessage: 'Keep the name under {{ limit }} characters.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'locked' => false,
        ]);
        $resolver->setAllowedTypes('locked', 'bool');
    }
}
