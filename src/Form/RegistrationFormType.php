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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Email;
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
                    // 3–30 : la borne de la page publique /p/{pseudo}, sinon un pseudo
                    // accepté ici n'aurait pas de page partageable.
                    new Length(min: 3, max: 30),
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
                    new Email(message: 'Cette adresse email n\'est pas valide.'),
                    new Length(max: 180),
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
                    new Length(min: 8, max: 4096, minMessage: 'Minimum 8 caractères'),
                ],
            ]);

        // Normalisé avant validation : « Mathieu@Mail.fr » et « mathieu@mail.fr » sont le
        // même compte, et l'unicité en base ne tolère qu'une graphie.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            if (isset($data['email']) && is_string($data['email'])) {
                $data['email'] = mb_strtolower(trim($data['email']));
            }
            if (isset($data['username']) && is_string($data['username'])) {
                $data['username'] = mb_strtolower(trim($data['username']));
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
