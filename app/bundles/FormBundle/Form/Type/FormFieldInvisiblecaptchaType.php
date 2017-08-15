<?php
namespace Mautic\FormBundle\Form\Type;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Class FormFieldHTMLType.
 */
class FormFieldInvisiblecaptchaType extends AbstractType
{
    private $client_key;

    public function __construct($client_key) {
        $this->client_key = $client_key;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $html = file_get_contents("app/bundles/FormBundle/Assets/html/grecaptcha_form_field.html");
        $html = sprintf($html, $this->client_key);

        $builder->add('text', 'textarea', [
            'label'      => 'mautic.form.field.type.invisiblecaptcha',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'style' => 'min-height:150px'],
            'required'   => true,
            'data' => $html
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults([
            'editor' => false,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'formfield_invisiblecaptcha';
    }
}
