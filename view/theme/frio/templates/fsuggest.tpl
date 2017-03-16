{{* Used in mod/fsuggest.php *}}
<h3 class="heading">{{$title}}</h3>
<div id="fsuggest-desc">{{$desc}}</div>
<form id="fsuggest-form" action="fsuggest/{{$contact_id}}" method="post">
	{{$contact_selector}}
	<div id="fsuggest-submit-wrapper"><button class="btn btn-primary" id="fsuggest-submit" type="submit" name="submit">{{$submit}}</button></div>
</form>