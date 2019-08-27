<?php

/**
 * Copyright © Bold Brand Commerce Sp. z o.o. All rights reserved.
 * See license.txt for license details.
 */

declare(strict_types = 1);

namespace Ergonode\Editor\Application\Controller\Api;

use Ergonode\Api\Application\Exception\DataValidationHttpException;
use Ergonode\Api\Application\Exception\FormValidationHttpException;
use Ergonode\Api\Application\Response\CreatedResponse;
use Ergonode\Api\Application\Response\EmptyResponse;
use Ergonode\Api\Application\Response\SuccessResponse;
use Ergonode\Attribute\Domain\Entity\AbstractAttribute;
use Ergonode\Attribute\Domain\Provider\AttributeValidationProvider;
use Ergonode\Core\Domain\ValueObject\Language;
use Ergonode\Designer\Domain\Builder\ViewTemplateBuilder;
use Ergonode\Designer\Domain\Repository\TemplateRepositoryInterface;
use Ergonode\Editor\Application\Form\DraftCreateForm;
use Ergonode\Editor\Application\Model\DraftCreateFormModel;
use Ergonode\Editor\Domain\Command\ChangeProductAttributeValueCommand;
use Ergonode\Editor\Domain\Command\CreateProductDraftCommand;
use Ergonode\Editor\Domain\Command\PersistProductDraftCommand;
use Ergonode\Editor\Domain\Entity\ProductDraftId;
use Ergonode\Editor\Domain\Provider\DraftProvider;
use Ergonode\Editor\Domain\Query\DraftQueryInterface;
use Ergonode\Editor\Infrastructure\Grid\ProductDraftGrid;
use Ergonode\Grid\RequestGridConfiguration;
use Ergonode\Grid\Response\GridResponse;
use Ergonode\Product\Domain\Entity\AbstractProduct;
use Ergonode\Product\Domain\Entity\ProductId;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Webmozart\Assert\Assert;

/**
 */
class ProductDraftController extends AbstractController
{
    /**
     * @var ProductDraftGrid
     */
    private $productDraftGrid;

    /**
     * @var DraftQueryInterface
     */
    private $draftQuery;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    /**
     * @var AttributeValidationProvider
     */
    private $provider;

    /**
     * @var DraftProvider
     */
    private $draftProvider;

    /**
     * @var ViewTemplateBuilder
     */
    private $builder;

    /**
     * @var TemplateRepositoryInterface
     */
    private $templateRepository;

    /**
     * @param ProductDraftGrid            $productDraftGrid
     * @param DraftQueryInterface         $draftQuery
     * @param MessageBusInterface         $messageBus
     * @param AttributeValidationProvider $provider
     * @param DraftProvider               $draftProvider
     * @param ViewTemplateBuilder         $builder
     * @param TemplateRepositoryInterface $templateRepository
     */
    public function __construct(
        ProductDraftGrid $productDraftGrid,
        DraftQueryInterface $draftQuery,
        MessageBusInterface $messageBus,
        AttributeValidationProvider $provider,
        DraftProvider $draftProvider,
        ViewTemplateBuilder $builder,
        TemplateRepositoryInterface $templateRepository
    ) {
        $this->productDraftGrid = $productDraftGrid;
        $this->draftQuery = $draftQuery;
        $this->messageBus = $messageBus;
        $this->provider = $provider;
        $this->draftProvider = $draftProvider;
        $this->builder = $builder;
        $this->templateRepository = $templateRepository;
    }

    /**
     * @Route("/products/drafts", methods={"GET"})
     *
     * @IsGranted("PRODUCT_READ")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="limit",
     *     in="query",
     *     type="integer",
     *     required=true,
     *     default="50",
     *     description="Number of returned lines",
     * )
     * @SWG\Parameter(
     *     name="offset",
     *     in="query",
     *     type="integer",
     *     required=true,
     *     default="0",
     *     description="Number of start line",
     * )
     * @SWG\Parameter(
     *     name="field",
     *     in="query",
     *     required=false,
     *     type="string",
     *     enum={"id", "product_id", "template_id", "sku", "type", "applied"},
     *     description="Order field",
     * )
     * @SWG\Parameter(
     *     name="order",
     *     in="query",
     *     required=false,
     *     type="string",
     *     enum={"ASC", "DESC"},
     *     description="Order",
     * )
     * @SWG\Parameter(
     *     name="filter",
     *     in="query",
     *     required=false,
     *     type="string",
     *     description="Filter"
     * )
     * @SWG\Parameter(
     *     name="show",
     *     in="query",
     *     required=false,
     *     type="string",
     *     enum={"COLUMN", "DATA"},
     *     description="Specify what response should containts"
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language code"
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Get draft grid"
     * )
     *
     * @ParamConverter(class="Ergonode\Grid\RequestGridConfiguration")
     *
     * @param Language                 $language
     * @param RequestGridConfiguration $configuration
     *
     * @return Response
     */
    public function getDrafts(Language $language, RequestGridConfiguration $configuration): Response
    {
        return new GridResponse($this->productDraftGrid, $configuration, $this->draftQuery->getDataSet(), $language);
    }

    /**
     * @Route("/products/{draft}", methods={"GET"}, requirements={"draft" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"})
     *
     * @IsGranted("PRODUCT_READ")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="draft",
     *     in="path",
     *     type="string",
     *     description="Product draft id",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Returns draft",
     * )
     * @SWG\Response(
     *     response=404,
     *     description="Not found",
     * )
     *
     * @param string   $draft
     * @param Language $language
     *
     * @return Response
     */
    public function getDraft(string $draft, Language $language): Response
    {
        $result = $this->draftQuery->getDraftView(new ProductDraftId($draft), $language);

        return new SuccessResponse($result);
    }

    /**
     * @Route("/products/drafts", methods={"POST"})
     *
     * @IsGranted("PRODUCT_CREATE")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="productId",
     *     in="formData",
     *     type="string",
     *     description="Product id",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * @SWG\Response(
     *     response=201,
     *     description="Create product draft based on product id",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Validation error",
     *     @SWG\Schema(ref="#/definitions/validation_error_response")
     * )
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function createDraft(Request $request): Response
    {
        $model = new DraftCreateFormModel();

        $form = $this->createForm(DraftCreateForm::class, $model);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var DraftCreateFormModel $data */
            $data = $form->getData();

            $command = new CreateProductDraftCommand(new productId($data->productId));
            $this->messageBus->dispatch($command);

            return new CreatedResponse($command->getId());
        }

        throw new FormValidationHttpException($form);
    }

    /**
     * @Route("/products/{product}/draft/persist", methods={"PUT"} ,requirements={"product" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"})
     *
     * @IsGranted("PRODUCT_UPDATE")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="product",
     *     in="path",
     *     type="string",
     *     description="Product id",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * @SWG\Response(
     *     response=204,
     *     description="Apply draft changes to product",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Validation error",
     *     @SWG\Schema(ref="#/definitions/validation_error_response")
     * )
     *
     * @param AbstractProduct $product
     *
     * @ParamConverter(class="Ergonode\Product\Domain\Entity\AbstractProduct")
     *
     * @return Response
     * @throws \Exception
     */
    public function applyDraft(AbstractProduct $product): Response
    {
        $draft = $this->draftProvider->provide($product);

        $command = new PersistProductDraftCommand($draft->getId());
        $this->messageBus->dispatch($command);

        return new EmptyResponse();
    }

    /**
     * @Route(
     *     "/products/{product}/draft/{attribute}/value",
     *     methods={"PUT"},
     *     requirements = {
     *        "product" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}",
     *        "attribute" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
     *     }
     * )
     *
     * @IsGranted("PRODUCT_UPDATE")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="product",
     *     in="path",
     *     type="string",
     *     description="Product draft id",
     * )
     * @SWG\Parameter(
     *     name="attribute",
     *     in="path",
     *     type="string",
     *     description="Attribute id",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * @SWG\Parameter(
     *     name="value",
     *     in="formData",
     *     type="string",
     *     description="Attribute value",
     *     required=true,
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Change product attribute Value",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Validation error",
     *     @SWG\Schema(ref="#/definitions/validation_error_response")
     * )
     *
     * @param AbstractProduct   $product
     * @param Language          $language
     * @param AbstractAttribute $attribute
     * @param Request           $request
     *
     * @ParamConverter(class="Ergonode\Product\Domain\Entity\AbstractProduct")
     * @ParamConverter(class="Ergonode\Attribute\Domain\Entity\AbstractAttribute")
     *
     * @return Response
     *
     * @throws \Exception
     *
     * @todo Refactor it to standard solution
     */
    public function changeDraftAttribute(AbstractProduct $product, Language $language, AbstractAttribute $attribute, Request $request): Response
    {
        $draft = $this->draftProvider->provide($product);

        $value = $request->request->get('value');

        $validator = $this->provider->provide($attribute);

        if (null !== $value && '' !== $value) {
            if ($validator->isValid($attribute, $value)) {
                $command = new ChangeProductAttributeValueCommand($draft->getId(), $attribute->getId(), $language, $value);
                $this->messageBus->dispatch($command);

                return new SuccessResponse(['value' => $value]);
            }
        } else {
            $command = new ChangeProductAttributeValueCommand($draft->getId(), $attribute->getId(), $language);
            $this->messageBus->dispatch($command);

            return new SuccessResponse(['value' => $value]);
        }

        throw new DataValidationHttpException([
            'value' => [sprintf('%s is incorrect value for %s attribute', $value, $attribute->getType())],
        ]);
    }

    /**
     * @Route("/products/{product}/draft", methods={"GET"} ,requirements={"product" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"})
     *
     * @IsGranted("PRODUCT_READ")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="product",
     *     in="path",
     *     type="string",
     *     description="Get product draft",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Return product draft model",
     * )
     * @SWG\Response(
     *     response=404,
     *     description="Not found",
     * )
     *
     * @param AbstractProduct $product
     *
     * @return Response
     *
     * @throws \Exception
     *
     * @ParamConverter(class="Ergonode\Product\Domain\Entity\AbstractProduct")
     */
    public function getProductDraft(AbstractProduct $product): Response
    {
        $draft = $this->draftProvider->provide($product);

        return new SuccessResponse($draft);
    }

    /**
     * @Route("/products/{product}/template", methods={"GET"} ,requirements={"product" = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"})
     *
     * @IsGranted("PRODUCT_READ")
     *
     * @SWG\Tag(name="Editor")
     * @SWG\Parameter(
     *     name="product",
     *     in="path",
     *     type="string",
     *     description="Get product draft",
     * )
     * @SWG\Parameter(
     *     name="language",
     *     in="path",
     *     type="string",
     *     required=true,
     *     default="EN",
     *     description="Language Code",
     * )
     * @SWG\Response(
     *     response=200,
     *     description="Return product template model",
     * )
     * @SWG\Response(
     *     response=404,
     *     description="Not found",
     * )
     *
     * @param AbstractProduct $product
     * @param Language        $language
     *
     * @return Response
     *
     * @throws \Exception
     *
     * @ParamConverter(class="Ergonode\Product\Domain\Entity\AbstractProduct")
     */
    public function getProductTemplate(AbstractProduct $product, Language $language): Response
    {
        $template = $this->templateRepository->load($product->getTemplateId());

        Assert::notNull($template);

        $view = $this->builder->build($template, $language);

        return new SuccessResponse($view);
    }
}
