<?php

namespace MediaWiki\Extension\MassRollback;

use HTMLForm;
use ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use OOUI\ButtonInputWidget;
use Wikimedia\Rdbms\SelectQueryBuilder;

class SpecialMassRollback extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MassRollback' );
    }

    public function getRestriction(): string {
        return 'massrollback';
    }

    public function doesWrites(): bool {
        return true;
    }

    public function execute( $par ): void {
        $this->setHeaders();
        $this->checkPermissions();
        $this->getOutput()->addModuleStyles( [ 'ext.massrollback.styles' ] );

        $request = $this->getRequest();

        if ( $request->wasPosted() && $request->getCheck( 'wpConfirm' ) ) {
            $this->handleExecute();
            return;
        }

        if ( $request->wasPosted() && $request->getCheck( 'username' ) ) {
            if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
                $this->getOutput()->addWikiMsg( 'massrollback-error-token' );
                return;
            }
            $this->showList(
                $request->getText( 'username' ),
                $request->getText( 'start' ),
                $request->getText( 'end' ),
                $request->getText( 'reason' )
            );
            return;
        }

        $this->showPromptForm( $par ?: $request->getVal( 'username', '' ) );
    }

    private function showPromptForm( string $prefillUsername = '' ): void {
        $form = HTMLForm::factory( 'ooui', [
            'username' => [
                'type' => 'user',
                'name' => 'username',
                'label-message' => 'massrollback-form-username',
                'default' => $prefillUsername,
                'required' => true,
            ],
            'start' => [
                'type' => 'date',
                'name' => 'start',
                'label-message' => 'massrollback-form-start',
                'required' => true,
            ],
            'end' => [
                'type' => 'date',
                'name' => 'end',
                'label-message' => 'massrollback-form-end',
                'default' => date( 'Y-m-d' ),
                'required' => true,
            ],
            'reason' => [
                'type' => 'text',
                'name' => 'reason',
                'label-message' => 'massrollback-form-reason',
                'required' => true,
            ],
        ], $this->getContext() );

        $form->setWrapperLegendMsg( 'massrollback-prompt-heading' )
            ->setSubmitTextMsg( 'massrollback-prompt-submit' )
            ->prepareForm()
            ->displayForm( false );
    }

    private function showList( string $username, string $start, string $end, string $reason ): void {
        $out = $this->getOutput();
        $out->enableOOUI();

        $user = User::newFromName( $username );
        if ( !$user || !$user->getId() ) {
            $out->addWikiMsg( 'massrollback-error-bad-user' );
            $this->showPromptForm();
            return;
        }

        $startTs = strtotime( $start );
        $endTs = strtotime( $end . ' 23:59:59' );
        if ( !$startTs || !$endTs || $startTs > $endTs ) {
            $out->addWikiMsg( 'massrollback-error-bad-dates' );
            $this->showPromptForm();
            return;
        }

        $maxPages = (int)$this->getConfig()->get( 'MassRollbackMaxPages' );
        $pages = $this->findCandidatePages( $user, $startTs, $endTs, $maxPages );

        if ( !$pages['rows'] ) {
            $out->addWikiMsg( 'massrollback-list-empty', $user->getName() );
            return;
        }

        if ( $pages['truncated'] ) {
            $out->addWikiMsg( 'massrollback-list-truncated', $maxPages );
        }

        $out->addWikiMsg( 'massrollback-list-heading', $user->getName(), count( $pages['rows'] ) );

        $rows = '';
        foreach ( $pages['rows'] as $row ) {
            $title = Title::makeTitle( $row->page_namespace, $row->page_title );
            $timestamp = $this->getLanguage()->userTimeAndDate( $row->rev_timestamp, $this->getUser() );
            $rows .= Html::rawElement( 'li', [],
                Html::check( 'wpPages[]', true, [ 'value' => $row->page_id ] ) . ' ' .
                Html::element( 'a', [ 'href' => $title->getLocalURL() ], $title->getPrefixedText() ) .
                ' ' . Html::element( 'span', [ 'class' => 'massrollback-list-date' ], $timestamp )
            );
        }

        $actionUrl = $this->getPageTitle()->getLocalURL();
        $out->addHTML(
            Html::openElement( 'form', [ 'method' => 'post', 'action' => $actionUrl, 'id' => 'massrollback-confirm-form' ] ) .
            Html::hidden( 'wpUsername', $username ) .
            Html::hidden( 'wpStart', $start ) .
            Html::hidden( 'wpEnd', $end ) .
            Html::hidden( 'wpReason', $reason ) .
            Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
            Html::rawElement( 'ul', [ 'class' => 'massrollback-list' ], $rows ) .
            ( new ButtonInputWidget( [
                'type' => 'submit',
                'name' => 'wpConfirm',
                'label' => $this->msg( 'massrollback-confirm-button' )->text(),
                'flags' => [ 'primary', 'progressive' ],
            ] ) )->toString() .
            Html::closeElement( 'form' )
        );
    }

    /**
     * @return array{rows: \stdClass[], truncated: bool}
     */
    private function findCandidatePages( User $user, int $startTs, int $endTs, int $maxPages ): array {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $actorId = MediaWikiServices::getInstance()->getActorNormalization()->findActorId( $user, $dbr );
        if ( $actorId === null ) {
            return [ 'rows' => [], 'truncated' => false ];
        }

        $rows = iterator_to_array( $dbr->newSelectQueryBuilder()
            ->select( [ 'rev_id', 'rev_timestamp', 'page_id', 'page_namespace', 'page_title' ] )
            ->from( 'revision' )
            ->join( 'page', null, 'rev_page = page_id' )
            ->where( [
                'rev_actor' => $actorId,
                'rev_id = page_latest',
                'rev_timestamp >= ' . $dbr->addQuotes( $dbr->timestamp( $startTs ) ),
                'rev_timestamp <= ' . $dbr->addQuotes( $dbr->timestamp( $endTs ) ),
            ] )
            ->orderBy( 'rev_timestamp', 'DESC' )
            ->limit( $maxPages + 1 )
            ->caller( __METHOD__ )
            ->fetchResultSet() );

        $truncated = count( $rows ) > $maxPages;
        if ( $truncated ) {
            $rows = array_slice( $rows, 0, $maxPages );
        }

        return [ 'rows' => $rows, 'truncated' => $truncated ];
    }

    private function buildRollbackSummary( Title $title, User $user, string $reason ): string {
        $services = MediaWikiServices::getInstance();
        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();
        $revisionStore = $services->getRevisionStore();

        $current = $revisionStore->getRevisionByTitle( $title );
        $actorId = $services->getActorNormalization()->findActorId( $user, $dbw );

        $targetRow = ( $current && $actorId !== null )
            ? $dbw->newSelectQueryBuilder()
                ->select( [ 'rev_id' ] )
                ->from( 'revision' )
                ->where( [
                    'rev_page' => $current->getPageId(),
                    'rev_actor != ' . $dbw->addQuotes( $actorId ),
                ] )
                ->orderBy( [ 'rev_timestamp', 'rev_id' ], SelectQueryBuilder::SORT_DESC )
                ->caller( __METHOD__ )
                ->fetchRow()
            : false;

        $target = $targetRow !== false ? $revisionStore->getRevisionById( (int)$targetRow->rev_id ) : null;

        if ( !$current || !$target ) {
            return $reason;
        }

        $revisionsBetween = $revisionStore->countRevisionsBetween(
            $current->getPageId(),
            $target,
            $current,
            1000,
            RevisionStore::INCLUDE_NEW
        );

        $currentEditor = $current->getUser( RevisionRecord::FOR_PUBLIC );
        $targetEditor = $target->getUser( RevisionRecord::FOR_PUBLIC );

        if ( !$currentEditor ) {
            $messageKey = 'revertpage-nouser';
        } elseif ( $this->getConfig()->get( MainConfigNames::DisableAnonTalk ) && !$currentEditor->isRegistered() ) {
            $messageKey = 'revertpage-anon';
        } else {
            $messageKey = 'revertpage';
        }

        $standardSummary = trim( $this->msg( $messageKey )->params( [
            $targetEditor ? $targetEditor->getName() : null,
            $currentEditor ? $currentEditor->getName() : null,
            $target->getId(),
            Message::dateTimeParam( $target->getTimestamp() ),
            $current->getId(),
            Message::dateTimeParam( $current->getTimestamp() ),
            $revisionsBetween,
        ] )->inContentLanguage()->text() );

        return $standardSummary . ' (' . $reason . ')';
    }

    private function handleExecute(): void {
        $request = $this->getRequest();
        $out = $this->getOutput();

        if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $out->addWikiMsg( 'massrollback-error-token' );
            return;
        }

        $username = $request->getText( 'wpUsername' );
        $reason = $request->getText( 'wpReason' );
        $pageIds = $request->getArray( 'wpPages' ) ?? [];

        $user = User::newFromName( $username );
        if ( !$user || !$user->getId() ) {
            $out->addWikiMsg( 'massrollback-error-bad-user' );
            return;
        }

        if ( !$pageIds ) {
            $out->addWikiMsg( 'massrollback-error-no-pages' );
            return;
        }

        $maxPages = (int)$this->getConfig()->get( 'MassRollbackMaxPages' );
        if ( count( $pageIds ) > $maxPages ) {
            $out->addWikiMsg( 'massrollback-error-too-many-pages', $maxPages );
            return;
        }

        $rollbackPageFactory = MediaWikiServices::getInstance()->getRollbackPageFactory();
        $performer = $this->getAuthority();

        $successCount = 0;
        $failures = [];
        $rolledBackTitles = [];

        foreach ( $pageIds as $pageId ) {
            $title = Title::newFromID( (int)$pageId );
            if ( !$title ) {
                continue;
            }

            $status = $rollbackPageFactory
                ->newRollbackPage( $title, $performer, $user )
                ->setSummary( $this->buildRollbackSummary( $title, $user, $reason ) )
                ->rollbackIfAllowed();

            if ( $status->isGood() ) {
                $successCount++;
                $rolledBackTitles[] = $title->getPrefixedText();
            } else {
                $failures[] = $title->getPrefixedText() . ': ' . Status::wrap( $status )->getMessage()->text();
            }
        }

        $logEntry = new ManualLogEntry( 'massrollback', 'execute' );
        $logEntry->setPerformer( $this->getUser() );
        $logEntry->setTarget( $user->getUserPage() );
        $logEntry->setComment( $reason );
        $logEntry->setParameters( [
            '4::count' => $successCount,
            '5::pages' => implode( ', ', $rolledBackTitles ),
        ] );
        $logEntry->insert();

        $out->addWikiMsg( 'massrollback-done', $successCount, $user->getName() );

        if ( $failures ) {
            $items = '';
            foreach ( $failures as $failure ) {
                $items .= Html::element( 'li', [], $failure );
            }
            $out->addWikiMsg( 'massrollback-failures', count( $failures ) );
            $out->addHTML( Html::rawElement( 'ul', [], $items ) );
        }
    }

    protected function getGroupName(): string {
        return 'users';
    }

}
