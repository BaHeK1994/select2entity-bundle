<?php

namespace Tetranz\Select2EntityBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tetranz\Select2EntityBundle\Form\Type\Select2EntityType;

class AutocompleteService
{
    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @param FormFactoryInterface $formFactory
     * @param ManagerRegistry $doctrine
     */
    public function __construct(FormFactoryInterface $formFactory, ManagerRegistry $doctrine)
    {
        $this->formFactory = $formFactory;
        $this->doctrine = $doctrine;
    }

    /**
     * @param Request $request
     * @param string $type
     * @param null $data
     * @param array $options
     * @return array
     */
    public function getAutocompleteResults(Request $request, string $type, $data = null, array $options = []): array
    {
        $form = $this->formFactory->create($type, $data, $options);

        $fieldName = $request->query->all()['field_name'] ?? '';
        if (is_array($fieldName)) {
            $fieldName = '';
        }

        if (!$form->has($fieldName)) {
            return ['results' => [], 'more' => false];
        }

        $formConfig = $form->get($request->get('field_name'))->getConfig();
        $formType = $formConfig->getType()->getInnerType();

        if (!$formType instanceof Select2EntityType) {
            return ['results' => [], 'more' => false];
        }

        $fieldOptions = $formConfig->getOptions();

        if (!isset($fieldOptions['class'])) {
            return ['results' => [], 'more' => false];
        }

        /** @var EntityRepository $repo */
        $repo = $this->doctrine->getRepository($fieldOptions['class']);

        $term = $request->query->all()['q'] ?? '';
        if (is_array($term)) {
            $term = '';
        }

        $countQB = $repo->createQueryBuilder('e');
        $countQB
            ->select($countQB->expr()->count('e'))
            ->where('e.' . $fieldOptions['property'] . ' LIKE :term')
            ->setParameter('term', '%' . $term . '%');

        $maxResults = $fieldOptions['page_limit'];
        $offset = ($request->get('page', 1) - 1) * $maxResults;

        $resultQb = $repo->createQueryBuilder('e');
        $resultQb
            ->where('e.' . $fieldOptions['property'] . ' LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->setMaxResults($maxResults)
            ->setFirstResult($offset);

        if (is_array($fieldOptions['callback']) || is_callable($fieldOptions['callback'])) {
            if (is_array($fieldOptions['callback'])) {
                $cb = $fieldOptions['callback']['cb'] ?? null;
                $cbCount = $fieldOptions['callback']['cbCount'] ?? null;
            } else {
                $cb = $cbCount = $fieldOptions['callback'];
            }

            if (is_callable($cb) && is_callable($cbCount)) {
                $cbCount($countQB, $request);
                $cb($resultQb, $request);
            }
        }

        $count = $countQB->getQuery()->getSingleScalarResult();
        $paginationResults = $resultQb->getQuery()->getResult();

        $result = ['results' => null, 'more' => $count > ($offset + $maxResults)];

        $accessor = PropertyAccess::createPropertyAccessor();

        $result['results'] = array_map(function ($item) use ($accessor, $fieldOptions) {
            return ['id' => $accessor->getValue($item, $fieldOptions['primary_key']), 'text' => $accessor->getValue($item, $fieldOptions['text_property'] ?? $fieldOptions['property'])];
        }, $paginationResults);

        return $result;
    }
}
