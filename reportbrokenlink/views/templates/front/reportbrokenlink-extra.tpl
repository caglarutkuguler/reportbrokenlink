{*
*	Module Name: Report Broken Link
*	Module URI: Please contact with info@megventure.com
*	Description: This adds a button to the product pages for the visitors to report broken links.
*	Version: 3.2.0
*	Author: MEG Venture
*
*	Copyright 2012, www.megventure.com (info@megventure.com)
*
*	This program is not a free software: you can't redistribute it and/or modify
*	it. All rights reserved.
*
*	This copyright notice  and licence should be retained in all modules based on this framework.
*	This does not affect your rights to assert copyright over your own original work.
*}

<link rel="stylesheet" type="text/css" href="{$module_dir|escape:'htmlall':'UTF-8'}views/css/reportbrokenlink.css"/>
<button type="button" class="btn btn-secondary btn-sm" id="report_link_button" data-toggle="modal"  data-target="#report_link_form"><i class="material-icons my-float">link_off</i> {l s='Report Broken Links' mod='reportbrokenlink'}</button>

<div
  class="modal fade"
  id="report_link_form"
  tabindex="-1"
  role="dialog"
  aria-labelledby="report_link_formLabel"
  aria-hidden="true"
>
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="report_link_formLabel">{l s='Report Broken Links' mod='reportbrokenlink'}</h5>
        <button
          type="button"
          class="close"
          data-dismiss="modal"
          aria-label="Close"
        >
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
			<div class="report_link_form_content">
				<div id="report_link_form_error"></div>
				<div class="form_container">
					<p class="intro_form">{l s='Please notify us if you see an inconsistency on this page. Select what you have noticed.' mod='reportbrokenlink'}</p>
					<div style="display: none;">
						<input id="virtual_product_name" name="virtual_product_name" type="text" value="{$rpl_product->name|escape:'htmlall':'UTF-8'}"/>
                        <input id="shop_email" name="shop_email" type="text" value="{$rpl_mail|escape:'htmlall':'UTF-8'}"/>
					</div>
				</div>
				<div class="form-group">
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox1" />
						<i class="md-checkbox-control"></i>
						{l s='Main product image/images are not displayed.' mod='reportbrokenlink'}
						</label>
					</div>
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox2" />
						<i class="md-checkbox-control"></i>
						{l s='Thumbnail product image/images of the main product are not displayed.' mod='reportbrokenlink'}
						</label>
					</div>
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox3" />
						<i class="md-checkbox-control"></i>
						{l s='There are some image files not displayed/found on the page.' mod='reportbrokenlink'}
						</label>
					</div>
					{if $virtual<>0}
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox4" />
						<i class="md-checkbox-control"></i>
						{l s='I purchased the virtual product, but not received the download file.' mod='reportbrokenlink'}
						</label>
					</div>
					{/if}
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox5" />
						<i class="md-checkbox-control"></i>
						{l s='The download file is missing or download link is not working.' mod='reportbrokenlink'}
						</label>
					</div>
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox6" />
						<i class="md-checkbox-control"></i>
						{l s='Some external links on this page are broken, not working.' mod='reportbrokenlink'}
						</label>
					</div>
					<div class="md-checkbox">
						<label>
						<input type="checkbox" name="checkbox7" />
						<i class="md-checkbox-control"></i>
						{l s='I cannot click any buttons on the page, there is something wrong.' mod='reportbrokenlink'}
						</label>
					</div>
				</div>
				<div class="form-group"> 
					<small><label class="form-control-label" for="visitoremail">{l s='If you leave your e-mail address, we will let you know about the fix (Optional)' mod='reportbrokenlink'}</label></small>
					<input type="text" class="form-control" id="visitoremail" name="visitoremail"/>
				</div>
				<div id="conf">{l s='Your report has been successfully sent to our team. Thank you!' mod='reportbrokenlink'}</div>
			</div>
      </div>
      <div class="modal-footer">
	  <p><i style="display:none;" id="loading"></i></p>
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-dismiss="modal"
        >
          {l s='Close' mod='reportbrokenlink'}
        </button>
		<input id="id_product_comment_send" name="id_product" type="hidden" value="{$rpl_product->id|escape:'htmlall':'UTF-8'}" />
		<button type="button" class="btn btn-primary" id="sendReport" name="sendReport"> {l s='Report Now' mod='reportbrokenlink'}</button>
      </div>
    </div>
  </div>
</div>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
<script type="text/javascript">
$(document).ready(function() {
	$('#sendReport').click(function(){
        document.getElementById("loading").style.display = 'unset';
        $('#loading').html('<img src = "../modules/reportbrokenlink/views/img/loading.gif" style="width: 15px;"/>');
		var datas = [];
		$('#report_link_form').find('input').each(function(index){
			var o = {};
			o.key = $(this).attr('name');
			o.value = $(this).val();
			o.checked = $(this)[0].checked;
			if (o.value != '')
				datas.push(o);
		});
		if (datas.length >= 3)
		{
			$.ajax({
				url: "{$base_dir}index.php?fc=module&module=reportbrokenlink&controller=ajax",
				post: "POST",
				data: {
						action: 'ReportLink', 
						secure_key: '{$rpl_secure_key}', 
						report: JSON.stringify(datas)
					},
				dataType: "json",
				success: function (result) {
					$("#conf").fadeIn('fast').delay(3000).fadeOut('slow');
					document.getElementById("loading").style.display = 'none';
					setTimeout(function(){
						jQuery('.close').trigger('click');
						},
						5000);
					//console.log(result);
				},
				error: function (jqXHR, exception) {
					console.log(jqXHR);
				}
			});
		}
		else
			$('#report_link_form_error').text('{l s="You did not fill required fields" mod="reportbrokenlink"}');
	});
});
</script>