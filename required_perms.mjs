// # Client Permissions
// These permissions must be granted to the AKSO bridge API client for this plugin to work properly.

export function getRequiredPerms(orgs) {
    const perms = [];
    const memberFields = [];

    orgs.forEach(org => {
        perms.push(`congresses.read.${org}`, `congress_instances.read.${org}`, `congress_instances.participants.read.${org}`);
    });
    // - used for the congress/instance selector in grav admin
    // - used for markdown congress fields, locations, program, and participants
    // - used for congress registration
    // - used for congress participations in account page

    orgs.forEach(org => {
        perms.push(`congress_instances.participants.update.${org}`, `congress_instances.participants.create.${org}`);
    });
    // - used for congress registration form

    perms.push(`codeholders.read`, `codeholder_roles.read`);

    {
        // member fields
        memberFields.push('roles');
        // - used for markdown codeholder lists

        memberFields.push(`honorific`, `firstNameLegal`, `lastNameLegal`, `firstName`, `lastName`, `lastNamePublicity`);
        // - used to render codeholder names in the congress participant list
        // - used for congress registration form autofill
        // - used for delegates
        // - used for markdown codeholder lists
        // - used for registration
        // - used for vote options

        memberFields.push(`searchName`);
        // - used for delegates

        memberFields.push(`fullName`, `nameAbbrev`, `fullNameLocal`, `careOf`);
        // - used for congress registration form address autofill
        // - used for markdown codeholder lists
        // - used for vote options

        memberFields.push(`birthdate`, `cellphone`, `landlinePhone`, `profession`, `feeCountry`);
        // - used for congress registration form autofill
        // - used for registration

        memberFields.push(`email`, `website`);
        // - used for congress registration form autofill
        // - used for markdown codeholder lists
        // - used for registration
        // - used for vote options

        memberFields.push(`officePhone`);
        // - used for congress registration form autofill
        // - used for omarkdown codeholder lists

        memberFields.push(`address.country`);
        // - used for congress registration form autofill
        // - used to automatically determine user's preferred country in country lists & delegates
        // - used for delegates
        // - used for markdown codeholder lists
        // - used for registration
        // - used for vote options

        memberFields.push(`address.countryArea`, `address.city`, `address.cityArea`, `address.streetAddress`, `address.postalCode`, `address.sortingCode`);
        // - used for congress registration form autofill
        // - used for delegates
        // - used for markdown codeholder lists
        // - used for registration

        memberFields.push(`mainDescriptor`, `factoids`, `biography`, `publicEmail`, `emailPublicity`, `officePhonePublicity`, `addressPublicity`, `publicCountry`);
        // - used for delegates
        // - used for markdown codeholder lists
        // - used for vote options

        memberFields.push(`codeholderType`, `profilePicture`, `profilePictureHash`, `profilePicturePublicity`);
        // - used for delegates
        // - used for markdown codeholder lists
        // - used for vote options

        memberFields.push(`membership`);
        // - used for registration

        memberFields.push(`hasPassword`);
        // - used to distinguish create/forgot password
    }

    perms.push(`codeholders.change_requests.read`, `codeholders.change_requests.update`);
    // - used for the user account page

    orgs.forEach(org => perms.push(`codeholders.delegations.read.${org}`));
    // - used for delegates

    orgs.forEach(org => perms.push(`delegations.subjects.read.${org}`));
    // - used for delegation applications

    perms.push(`registration.options.read`, `membership_categories.read`, `registration.entries.read`, `registration.entries.create`, `registration.entries.update`);
    orgs.forEach(org => perms.push(`congress_instances.participants.update.${org}`));
    // - used for registration
    // - (the update perms are required for payment intent triggers)

    orgs.forEach(org => perms.push(`pay.read.${org}`, `pay.payment_intents.create.${org}`));
    // - used for congress registration
    // - used for payment
    // - used for registration

    orgs.forEach(org => perms.push(`pay.payment_intents.intermediary.${org}.*`));
    // - used for registration

    perms.push(`countries.lists.read`);
    // - used to load country lists

    perms.push(`geodb.read`);
    // - used for delegates
    // - used for delegation applications

    orgs.forEach(org => {
        perms.push(`delegations.applications.read.${org}`, `delegations.applications.create.${org}`, `delegations.applications.delete.${org}`);
    });
    // - used for delegation applications

    orgs.forEach(org => perms.push(`magazines.read.${org}`, `magazines.subscriptions.read.${org}`));
    // - used for magazines
    // - used for markdown magazine lists
    // - used for registration

    perms.push(`lists.read`);
    // - used for markdown codeholder lists

    perms.push(`intermediaries.read`);
    // - used for intermediary payment methods

    orgs.forEach(org => perms.push(`newsletters.${org}.read`));
    // - used for user notification settings / newsletter subscriptions
    // - used for GK send to subscribers

    orgs.forEach(org => perms.push(`notif_templates.read.${org}`, `notif_templates.create.${org}`));
    // - used for GK send to subscribers

    orgs.forEach(org => perms.push(`notif_templates.delete.${org}`));
    // - used for GK send to subscribers (delete on complete)

    perms.push(`ratelimit.disable`);
    // - so the website doesn't get rate limited

    return { perms, memberFields: Object.fromEntries(memberFields.map(f => [f, 'r'])) };
}
