<?php

namespace MediaWiki\Extension\MassRollback;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class Hooks {

    public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
        if ( !$specialPage->getAuthority()->isAllowed( 'massrollback' ) ) {
            return;
        }

        $username = $title->getText();
        $tools['massrollback'] = $specialPage->getLinkRenderer()->makeKnownLink(
            SpecialPage::getTitleFor( 'MassRollback', $username ),
            $specialPage->msg( 'massrollback-linkoncontribs' )->text()
        );
    }

}
