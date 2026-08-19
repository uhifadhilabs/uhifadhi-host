<?php

declare(strict_types=1);

namespace Uhifadhi\Dashboard\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The new-area upload: a name and a boundary file in any common GIS format
 * (GeoJSON, zipped Shapefile, GeoPackage, KML/KMZ, zipped File Geodatabase).
 * Format validation happens in BoundaryImportService, which reads the file for
 * real — the form only guards the basics.
 */
final class AreaUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Area name',
                'constraints' => [new NotBlank(), new Length(max: 128)],
            ])
            ->add('boundaryFile', FileType::class, [
                'label' => 'Boundary file',
                'help' => 'GeoJSON, zipped Shapefile, GeoPackage, KML/KMZ, or zipped File Geodatabase — upload what your GIS office hands you.',
                'constraints' => [new NotBlank(), new File(maxSize: '100M')],
            ]);
    }
}
