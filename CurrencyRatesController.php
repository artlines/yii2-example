<?php

namespace app\controllers;

use app\core\repositories\NotFoundException;
use app\core\services\Staff\CurrencyBankRatesService;
use app\core\vo\Currency;
use Yii;
use app\core\services\Staff\CurrencyRatesManageService;
use app\forms\Staff\CurrencyRateForm;
use app\models\CurrencyRatesSearch;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class CurrencyRatesController extends Controller
{
    private $currencyRatesManageService;

    private $user;
    private $currencyBankRatesService;

    public function __construct(
        $id,
        $module,
        CurrencyRatesManageService $currencyRatesManageService,
        CurrencyBankRatesService $currencyBankRatesService,
        $config = []
    ) {
        parent::__construct($id, $module, $config);

        $this->currencyBankRatesService = $currencyBankRatesService;
        $this->currencyRatesManageService = $currencyRatesManageService;
        $this->user = Yii::$app->user->identity;
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST']
                ]
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['index'],
                        'roles' => ['currencyRatesView', 'currencyRatesManage']
                    ],
                    [
                        'allow' => true,
                        'actions' => ['create', 'update', 'delete', 'get-currency-bank-rate'],
                        'roles' => ['currencyRatesManage']
                    ]
                ]
            ]
        ];
    }

    public function actionIndex()
    {
        $searchModel = new CurrencyRatesSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'currencyBankRates' => $this->currencyBankRatesService->getAll()
        ]);
    }

    public function actionCreate()
    {
        $form = new CurrencyRateForm();

        try {
            $currencyBankRate = $this->currencyBankRatesService->getByCode($form->getCurrency());
        } catch (NotFoundException $notFoundException) {
            $currencyBankRate = null;
            Yii::$app->errorHandler->logException($notFoundException);
        }

        $form->setCurrencyBankRate($currencyBankRate);

        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            try {
                $this->currencyRatesManageService->create($form, $this->user->username);
                Yii::$app->session->setFlash('success', 'Курс валюты добавлен.');

                return $this->redirect(['index']);
            } catch (\DomainException $exception) {
                Yii::$app->errorHandler->logException($exception);
                Yii::$app->session->setFlash('error', $exception->getMessage());
            }
        }

        return $this->render('create', [
            'model' => $form
        ]);
    }

    public function actionUpdate(int $id)
    {
        $model = $this->currencyRatesManageService->get($id);
        $form = new CurrencyRateForm($model);

        try {
            $currencyBankRate = $this->currencyBankRatesService->getByCode($form->getCurrency());
        } catch (NotFoundException $notFoundException) {
            $currencyBankRate = null;
            Yii::$app->errorHandler->logException($notFoundException);
        }

        $form->setCurrencyBankRate($currencyBankRate);

        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            try {
                $this->currencyRatesManageService->edit($model->getId(), $form, $this->user->username);
                Yii::$app->session->setFlash('success', 'Курс валюты обновлен.');

                return $this->redirect(['index']);
            } catch (\DomainException $exception) {
                Yii::$app->errorHandler->logException($exception);
                Yii::$app->session->setFlash('error', $exception->getMessage());
            }
        }

        return $this->render('update', [
            'model' => $form
        ]);
    }

    public function actionDelete(int $id)
    {
        $model = $this->currencyRatesManageService->get($id);

        try {
            $this->currencyRatesManageService->remove($model);
            Yii::$app->session->setFlash('success', 'Курс валюты удален.');
        } catch (\DomainException $exception) {
            Yii::$app->errorHandler->logException($exception);
            Yii::$app->session->setFlash('error', $exception->getMessage());
        }

        return $this->redirect(['index']);
    }

    public function actionGetCurrencyBankRate(string $code): Response
    {
        return $this->asJson($this->currencyBankRatesService->getByCode(new Currency($code)));
    }
}
