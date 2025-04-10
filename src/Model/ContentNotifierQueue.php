<?php

namespace SilverStripe\ContentNotifier\Model;

use Psr\Container\NotFoundExceptionInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use UncleCheese\BetterButtons\Actions\BetterButtonCustomAction;
use UncleCheese\BetterButtons\Actions\BetterButtonLink;

class ContentNotifierQueue extends DataObject
{
    /**
     * @var array|string[]
     */
    private static array $db = [
        'RecordClass' => 'Varchar',
        'RecordID' => 'Int',
        'Event' => "Enum('CREATED,UPDATED')",
        'HasNotified' => 'Boolean',
    ];

    /**
     * @var array|string[]
     */
    private static array $summary_fields = [
        'Created.Nice' => 'Created',
        'Event' => 'Event',
        'RecordClass' => 'Content type',
        'Status' => 'Status',
    ];

    /**
     * @var array|string[]
     */
    private static array $better_buttons_actions = [
        'approve',
        'deny',
    ];

    /**
     * @var array|string[]
     */
    private static array $default_sort = [
        "Created" => "DESC",
    ];

    /**
     * @var string
     */
    private static string $table_name = 'ContentNotifierQueue';

    /**
     * @return DataList
     */
    public static function get_unnotified(): DataList
    {
        return self::get()->filter([
            'HasNotified' => false,
        ]);
    }

    /**
     * @return FieldList
     */
    public function getCMSFields(): FieldList
    {
        if (!$this->getRecord()) {
            return FieldList::create();
        }

        $fields = $this->getRecord()->getCMSFields();
        $fields->unshift(
            LiteralField::create("stat", "<h3 style='margin-left:10px;'>Status: " . $this->getRecord()->getStatus() . "</h3>")
        );

        // Create a dummy form so we can get access to loadDataFrom(). :-(
        return Form::create(Controller::curr(), "dummy", $fields, FieldList::create())
            ->loadDataFrom($this->getRecord())
            ->Fields()
            ->makeReadonly();
    }

    /**
     * @return string
     * @throws NotFoundExceptionInterface
     */
    public function Category(): string
    {
        return Injector::inst()->get($this->RecordClass)->plural_name();
    }

    /**
     * @return DataObject|null
     * @throws NotFoundExceptionInterface
     */
    public function getRecord(): ?DataObject
    {
        $class = Injector::inst()->get($this->RecordClass);
        return DataList::create(get_class($class))->byID($this->RecordID);
    }

    /**
     * @return string
     * @throws NotFoundExceptionInterface
     */
    public function getTitle(): string
    {
        if ($this->getRecord()) {
            return "[{$this->RecordClass}] " . $this->getRecord()->getTitle();
        }

        return "";
    }

    /**
     * @return FieldList
     * @throws NotFoundExceptionInterface
     */
    public function getBetterButtonsActions(): FieldList
    {
        $fields = parent::getBetterButtonsActions();
        if (!$this->getRecord()) {
            return $fields;
        }

        if ($this->getRecord()->ContentNotifierApproved) {
            $fields->push(
                BetterButtonCustomAction::create('deny', 'Deny')
                    ->setRedirectType(BetterButtonCustomAction::REFRESH)
            );
        } else {
            $fields->push(
                BetterButtonCustomAction::create('approve', 'Approve')
                    ->setRedirectType(BetterButtonCustomAction::REFRESH)
            );
        }

        $fields->push(
            new BetterButtonLink(
                'Edit this ' . $this->RecordClass,
                $this->getRecord()->getContentNotifierLink()
            )
        );

        return $fields;
    }

    /**
     * @return string
     * @throws NotFoundExceptionInterface
     */
    public function getStatus(): string
    {
        if ($this->getRecord()) {
            return $this->getRecord()->getStatus();
        }

        return '';
    }

    /**
     * @return string
     * @throws NotFoundExceptionInterface
     */
    public function approve(): string
    {
        if ($this->getRecord()) {
            $this->getRecord()->approve();

            return 'Approved for publication';
        }

        return '';
    }

    /**
     * @return string
     * @throws NotFoundExceptionInterface
     */
    public function deny(): string
    {
        if ($this->getRecord()) {
            $this->getRecord()->deny();

            return 'Denied for publication';
        }

        return '';
    }

    /**
     * @param $member
     * @return bool|int
     */
    public function canEdit($member = null): bool|int
    {
        return Permission::check("CMS_ACCESS_ContentNotifierAdmin");
    }

    /**
     * @param $member
     * @return bool|int
     */
    public function canView($member = null): bool|int
    {
        return Permission::check("CMS_ACCESS_ContentNotifierAdmin");
    }

    /**
     * @param $member
     * @return bool|int
     */
    public function canDelete($member = null): bool|int
    {
        return Permission::check("CMS_ACCESS_ContentNotifierAdmin");
    }

    /**
     * @param $member
     * @param $context
     * @return false
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
