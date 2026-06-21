import { Dashboard } from './views/Dashboard';
import { General } from './views/General';
import { Titles } from './views/Titles';
import { Social } from './views/Social';
import { Sitemaps } from './views/Sitemaps';
import { Schema } from './views/Schema';
import { Ai } from './views/Ai';

const VIEWS = {
	dashboard: Dashboard,
	general: General,
	titles: Titles,
	social: Social,
	sitemaps: Sitemaps,
	schema: Schema,
	ai: Ai,
};

export function App( { view } ) {
	const View = VIEWS[ view ] ?? Dashboard;
	return <View />;
}
