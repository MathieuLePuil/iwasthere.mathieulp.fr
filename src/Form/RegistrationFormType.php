<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Nom affiché',
                'attr' => ['placeholder' => 'Votre nom', 'class' => 'input-field'],
                'constraints' => [
                    new NotBlank(message: 'Le nom est requis'),
                    new Length(min: 2, max: 100),
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'Identifiant (@)',
                'attr' => ['placeholder' => 'ex: mathieulpl', 'class' => 'input-field'],
                'constraints' => [
                    new NotBlank(message: "L'identifiant est requis"),
                    new Length(min: 3, max: 50),
                    new Regex(
                        pattern: '/^[a-z0-9_]+$/',
                        message: 'Lettres minuscules, chiffres et _ uniquement',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['placeholder' => 'votre@email.com', 'class' => 'input-field'],
                'constraints' => [
                    new NotBlank(message: "L'email est requis"),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr' => ['placeholder' => 'Mot de passe', 'class' => 'input-field'],
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr' => ['placeholder' => 'Confirmer', 'class' => 'input-field'],
                ],
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Le mot de passe est requis'),
                    new Length(min: 8, minMessage: 'Minimum 8 caractères'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
