<?php

namespace App\Website\Elements;

enum WebsiteElementType: string
{
    case Heading = 'heading';
    case Text = 'text';
    case Date = 'date';
    case Accordion = 'accordion';
    case Schedule = 'schedule';
    case People = 'people';
    case Image = 'image';
    case Media = 'media';
    case Divider = 'divider';
    case Quote = 'quote';
    case Cta = 'cta';
    case CompositionGroup = 'compositionGroup';
    case EventDate = 'eventDate';
    case EventTime = 'eventTime';
    case Countdown = 'countdown';
}
