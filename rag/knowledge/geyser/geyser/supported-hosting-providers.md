# Supported Hosting Providers

This list is incomplete. Please open a PR or contact us on Discord if the information is incorrect or you want to add a new hosting provider!

	It should also be noted that these providers may not be verified by the Geyser team, and the server providers below are reported as working by members of the community.

	Using a <i>"Free Host"</i> may not give you the best experience <i>at all</i>. If you want better support, more freedom to control your server, and learn how to run one, PLEASE pay for a server.

	The below information is for the plugin versions of Geyser unless otherwise specified

## Built-in Geyser
{% for provider in site.data.providers.built_in %}
* [](){% if provider.description != nil or provider.description_template != nil %} -  {% endif %}
{% endfor %}

## Support for Geyser
{% for provider in site.data.providers.support %}
* [](){% if provider.description != nil or provider.description_template != nil %} -  {% endif %}
{% endfor %}

## Does not support Geyser
{% for provider in site.data.providers.no_support %}
* [](){% if provider.description != nil or provider.description_template != nil %} -  {% endif %}
{% endfor %}
