<?php

namespace App\Website\Elements;

enum CtaActionType: string
{
    case Rsvp = 'rsvp';
    case ScrollToSection = 'scrollToSection';
    case ViewSchedule = 'viewSchedule';
    case ViewGallery = 'viewGallery';
    case BackToTop = 'backToTop';
    case ExternalUrl = 'externalUrl';
}
